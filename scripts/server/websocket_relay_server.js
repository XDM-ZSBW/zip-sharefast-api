/**
 * WebSocket Relay Server (Node.js) - OPTIMIZED for 60 FPS
 * 
 * High-performance WebSocket server for ShareFast relay connections
 * Uses in-memory storage and direct forwarding for maximum performance
 * 
 * Optimizations:
 * - In-memory frame buffers (no file I/O for active sessions)
 * - Direct peer-to-peer forwarding when both connected
 * - Binary WebSocket frames support (removes JSON/base64 overhead)
 * - Event-driven forwarding (no polling needed)
 * - Reduced logging overhead (no per-frame logging)
 * - Smaller buffer sizes (10 frames max vs 60)
 * - O(1) peer lookup with Map
 * 
 * Usage:
 *   npm install ws
 *   node websocket_relay_server.js
 * 
 * Or with PM2:
 *   pm2 start websocket_relay_server.js --name sharefast-websocket --node-args="--max-old-space-size=512"
 */

const WebSocket = require('ws');
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');

// Configuration - SSL is ALWAYS required
const SSL_PORT = process.env.SSL_PORT || 8767;
const SSL_CERT_PATH = process.env.SSL_CERT_PATH || '/etc/apache2/ssl/sharefast.zip.crt';
const SSL_KEY_PATH = process.env.SSL_KEY_PATH || '/etc/apache2/ssl/sharefast.zip.key';
const RELAY_STORAGE_PATH = process.env.RELAY_STORAGE_PATH || './storage/relay/';
// Force to use sharefast.zip - never use futurelink.zip
const PHP_API_URL = process.env.PHP_API_URL ? 
    process.env.PHP_API_URL.replace(/connect\.futurelink\.zip|futurelink\.zip/g, 'sharefast.zip') : 
    'https://sharefast.zip/api/';
const DEBUG = process.env.DEBUG === 'true'; // Enable debug logging only when needed
// Enable debug logging for peer connection issues
const PEER_DEBUG = true; // Always log peer connection events

// Create storage directory if it doesn't exist (for fallback only)
if (!fs.existsSync(RELAY_STORAGE_PATH)) {
    fs.mkdirSync(RELAY_STORAGE_PATH, { recursive: true });
}

// SSL is ALWAYS required - fail if certificates not found
if (!fs.existsSync(SSL_CERT_PATH) || !fs.existsSync(SSL_KEY_PATH)) {
    const errorMsg = `SSL certificates not found. Certificate: ${SSL_CERT_PATH}, Key: ${SSL_KEY_PATH}. WebSocket requires SSL/WSS only.`;
    console.error(`[WebSocket] FATAL ERROR: ${errorMsg}`);
    process.exit(1);
}

// Create HTTPS server with SSL (SSL is mandatory)
const options = {
    cert: fs.readFileSync(SSL_CERT_PATH),
    key: fs.readFileSync(SSL_KEY_PATH)
};

const server = https.createServer(options);
const wss = new WebSocket.Server({ 
    server,
    // Reject non-SSL connections
    verifyClient: (info) => {
        const isSecure = info.secure || (info.req && info.req.socket && info.req.socket.encrypted);
        if (!isSecure) {
            if (DEBUG) console.warn(`[WebSocket] Rejected non-SSL connection attempt from ${info.req.socket.remoteAddress}`);
            return false;
        }
        return true;
    },
    // NAT-friendly: Disable compression entirely to avoid packet fragmentation issues
    // Compression can cause SSL/TLS record MAC errors when packets are fragmented by NAT
    // Frames are already JPEG compressed, so additional compression provides minimal benefit
    perMessageDeflate: false
});

console.log(`[WebSocket] SSL enabled - using certificates:`);
console.log(`  Certificate: ${SSL_CERT_PATH}`);
console.log(`  Key: ${SSL_KEY_PATH}`);

// OPTIMIZATION: In-memory storage for active sessions (much faster than file I/O)
const activeSessions = new Map(); // session_id -> { ws, mode, peerId, peerWs }
// OPTIMIZATION: Map for O(1) lookup by peerId
const sessionsByPeerId = new Map(); // peerId -> sessionId
const frameBuffers = new Map(); // peerId -> Array of { type, data, timestamp }
const MAX_BUFFER_SIZE = 10; // Reduced from 60 - only keep last 10 frames (~166ms at 60 FPS)

// Function to get peer_id from PHP API
async function getPeerId(sessionId, code) {
    try {
        const fetch = require('node-fetch');
        const https = require('https');
        
        // Create agent that accepts self-signed certificates for internal API calls
        const httpsAgent = new https.Agent({
            rejectUnauthorized: false  // Accept self-signed certificates for internal API
        });
        
        const response = await fetch(`${PHP_API_URL}register.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: code,
                mode: 'query',
                session_id: sessionId
            }),
            agent: PHP_API_URL.startsWith('https://') ? httpsAgent : undefined
        });
        
        if (!response.ok) {
            if (DEBUG) console.error(`[WebSocket] getPeerId HTTP error: ${response.status} ${response.statusText}`);
            return null;
        }
        
        const data = await response.json();
        return data.peer_id || null;
    } catch (error) {
        if (DEBUG) console.error('[WebSocket] Error getting peer_id:', error.message || error);
        return null;
    }
}

// OPTIMIZATION: Direct forwarding function (no file I/O, O(1) lookup)
function forwardToPeer(peerId, dataType, data) {
    // OPTIMIZATION: O(1) lookup instead of O(n) search
    const peerSessionId = sessionsByPeerId.get(peerId);
    if (peerSessionId) {
        const peerSession = activeSessions.get(peerSessionId);
        if (peerSession && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
            // OPTIMIZATION: Send binary frame directly (no JSON/base64 overhead)
            try {
                // Format: [type:1byte][length:4bytes][data:bytes]
                const typeByte = dataType === 'frame' ? 0x01 : dataType === 'input' ? 0x02 : dataType === 'cursor' ? 0x04 : 0x01; // 0x01=frame, 0x02=input, 0x04=cursor
                const lengthBuffer = Buffer.allocUnsafe(4);
                lengthBuffer.writeUInt32BE(data.length, 0);
                
                const message = Buffer.concat([
                    Buffer.from([typeByte]),
                    lengthBuffer,
                    data
                ]);
                
                peerSession.ws.send(message, { binary: true });
                return true;
            } catch (error) {
                if (DEBUG) console.error(`[WebSocket] Error forwarding to peer: ${error}`);
                return false;
            }
        }
    }
    
    // Fallback: Store in buffer if peer not connected yet
    if (!frameBuffers.has(peerId)) {
        frameBuffers.set(peerId, []);
    }
    
    // OPTIMIZATION: Reduced buffer size (10 frames max vs 60)
    const buffer = frameBuffers.get(peerId);
    buffer.push({
        type: dataType,
        data: data,
        timestamp: Date.now()
    });
    
    // Keep only last 10 frames (reduced from 60)
    if (buffer.length > MAX_BUFFER_SIZE) {
        const dropped = buffer.shift();
        // Free memory immediately
        dropped.data = null;
    }
    
    return false;
}

// OPTIMIZATION: Flush buffered frames to newly connected peer
function flushBufferToPeer(peerId, peerWs) {
    if (!frameBuffers.has(peerId)) {
        return;
    }
    
    const buffer = frameBuffers.get(peerId);
    let flushed = 0;
    while (buffer.length > 0 && peerWs.readyState === WebSocket.OPEN && flushed < MAX_BUFFER_SIZE) {
        const frame = buffer.shift();
        try {
            const typeByte = frame.type === 'frame' ? 0x01 : frame.type === 'input' ? 0x02 : frame.type === 'cursor' ? 0x04 : 0x01;
            const lengthBuffer = Buffer.allocUnsafe(4);
            lengthBuffer.writeUInt32BE(frame.data.length, 0);
            
            const message = Buffer.concat([
                Buffer.from([typeByte]),
                lengthBuffer,
                Buffer.from(frame.data)
            ]);
            
            peerWs.send(message, { binary: true });
            flushed++;
        } catch (error) {
            if (DEBUG) console.error(`[WebSocket] Error flushing buffer: ${error}`);
            break;
        }
    }
    
    // Clear buffer after flushing (don't wait)
    frameBuffers.delete(peerId);
}

wss.on('connection', (ws, req) => {
    const url = new URL(req.url, `https://${req.headers.host}`);
    const sessionId = url.searchParams.get('session_id');
    const code = url.searchParams.get('code');
    const mode = url.searchParams.get('mode') || 'client';
    
    // Log connection to verify handler is being called
    console.log(`[WebSocket] Connection handler CALLED for ${sessionId} (${mode}), code=${code}`);
    
    // Verify SSL connection
    if (!req.socket || !req.socket.encrypted) {
        if (DEBUG) console.warn(`[WebSocket] Non-SSL connection attempt rejected from ${req.socket.remoteAddress}`);
        ws.close(1008, 'SSL required. WebSocket connections must use WSS (secure WebSocket).');
        return;
    }
    
    // NAT-friendly settings for this connection
    // Set keepalive to prevent NAT timeouts (send ping every 20 seconds)
    ws._socket.setKeepAlive(true, 20000);  // Enable TCP keepalive, probe every 20 seconds
    // Disable Nagle's algorithm for lower latency (important for NAT)
    ws._socket.setNoDelay(true);
    
    if (!sessionId || !code) {
        ws.close(1008, 'Missing session_id or code');
        return;
    }
    
    if (PEER_DEBUG) console.log(`[WebSocket] New connection: ${mode} - session_id=${sessionId}, code=${code}`);
    
    // Get peer_id
    let peerId = null;
    let session = {
        ws: ws,
        mode: mode,
        peerId: null,
        peerWs: null
    };
    
    activeSessions.set(sessionId, session);
    
    // Call getPeerId immediately and log it
    if (PEER_DEBUG) console.log(`[WebSocket] Calling getPeerId for ${sessionId} (${mode}) with code ${code}`);
    getPeerId(sessionId, code).then(pid => {
        if (PEER_DEBUG) console.log(`[WebSocket] getPeerId completed for ${sessionId} (${mode}): peerId=${pid || 'null'}`);
        peerId = pid;
        session.peerId = pid;
        
        if (!peerId) {
            // Don't send error - peer might not be connected yet
            // Just log and wait for peer to connect
            if (DEBUG) console.log(`[WebSocket] No peer_id found yet for session ${sessionId} (${mode}) - peer may connect later`);
            // Don't send error - wait for peer connection
            return;
        }
        
        // OPTIMIZATION: Update peer lookup map for O(1) access
        // Map: peerId (client's session_id) -> admin's session_id
        sessionsByPeerId.set(peerId, sessionId);
        
        // OPTIMIZATION: Try to find peer connection and link them (O(1) lookup)
        // Strategy: peerId is the session_id of the peer we're looking for
        // - If admin connects: peerId = client's session_id, check if client is already connected
        // - If client connects: peerId = admin's session_id, check if admin is already connected
        let peerFound = false;
        
        // Check if peer is already connected (peerId is the session_id we're looking for)
        const peerSession = activeSessions.get(peerId);
        if (peerSession && peerSession.mode !== mode) {
            // Found peer! Link them for direct forwarding
            session.peerWs = peerSession.ws;
            peerSession.peerWs = session.ws;
            peerFound = true;
            
            // Also update the reverse lookup (peerId -> sessionId mapping)
            sessionsByPeerId.set(sessionId, peerId);
            
            if (PEER_DEBUG) {
                console.log(`[WebSocket] *** Peer connection established: ${sessionId} (${mode}) <-> ${peerId} (${peerSession.mode}) ***`);
            }
            
            // Flush any buffered frames to newly connected peer
            if (mode === 'admin') {
                flushBufferToPeer(peerId, session.ws);
            } else {
                // Client connected - flush frames buffered for admin
                flushBufferToPeer(sessionId, peerSession.ws);
            }
        }
        
        if (!peerFound) {
            if (PEER_DEBUG) {
                console.log(`[WebSocket] Peer not found yet for ${sessionId} (${mode}), peerId=${peerId || 'null'} - peer may connect later`);
            }
        }
    });
    
    // OPTIMIZATION: Handle both JSON (backward compat) and binary messages
    // CRITICAL: Handle 'message' event - this should receive ALL messages (text and binary)
    console.log(`[WebSocket] Attaching message handler for ${sessionId} (${mode})`);
    ws.on('message', (message, isBinary) => {
        // ALWAYS log EVERY message for first 100 to verify handler is being called
        if (!session._handler_called) {
            session._handler_called = true;
            console.log(`[WebSocket] Message handler CALLED for ${sessionId} (${mode}): isBinary=${isBinary}, type=${typeof message}, isBuffer=${Buffer.isBuffer(message)}, length=${Buffer.isBuffer(message) ? message.length : (typeof message === 'string' ? message.length : 'unknown')}`);
        }
        // Log ALL messages (text and binary) for first 100 to debug cursor issue
        if (!session._all_msg_count) session._all_msg_count = 0;
        session._all_msg_count++;
        
        // CRITICAL: Always log first 200 messages to catch cursor messages
        if (session._all_msg_count <= 200) {
            const msgType = typeof message;
            const msgLen = Buffer.isBuffer(message) ? message.length : (typeof message === 'string' ? message.length : 'unknown');
            const preview = typeof message === 'string' ? message.substring(0, 100) : (Buffer.isBuffer(message) && message.length > 0 ? `BINARY[${message.length}]` : 'EMPTY');
            console.log(`[ALL-MSG] Message #${session._all_msg_count} from ${sessionId} (${mode}): isBinary=${isBinary}, type=${msgType}, length=${msgLen}, preview=${preview}`);
        }
        
        // SPECIAL: Always log text messages (non-binary) to catch cursor messages
        // Log ALL text messages, not just first 100, to catch cursor messages
        if (!isBinary && typeof message === 'string') {
            const preview = message.substring(0, 200);
            // Always log text messages - they're rare and important for cursor
            console.log(`[TEXT-MSG] Message #${session._all_msg_count} from ${sessionId} (${mode}): length=${message.length}, preview=${preview}`);
        }
        
        // Also log small binary messages that might be text misclassified
        if (isBinary && Buffer.isBuffer(message) && message.length < 200) {
            // Log first 50 small binary messages to catch misclassified text
            if (!session._small_binary_count) session._small_binary_count = 0;
            session._small_binary_count++;
            if (session._small_binary_count <= 50) {
                try {
                    const textPreview = message.toString('utf-8').substring(0, 200);
                    console.log(`[SMALL-BINARY] Message #${session._all_msg_count} from ${sessionId} (${mode}): length=${message.length}, preview=${textPreview}`);
                } catch (e) {
                    console.log(`[SMALL-BINARY] Message #${session._all_msg_count} from ${sessionId} (${mode}): length=${message.length}, not UTF-8`);
                }
            }
        }
        
        // SPECIAL: Check if binary message might actually be text (cursor JSON)
        // Sometimes websocket-client sends strings as binary with UTF-8 encoding
        // Check BEFORE the binary handler processes it
        let messageConverted = false;
        if (isBinary && Buffer.isBuffer(message) && message.length < 100) {
            // Small binary message - might be text misclassified
            try {
                const text = message.toString('utf-8');
                if (text.startsWith('{"type":"cursor"') || text.startsWith('{"type": "cursor"')) {
                    console.log(`[CURSOR-BINARY] Found cursor in binary message #${session._all_msg_count} from ${sessionId} (${mode}): ${text}`);
                    // Treat as text message - convert it
                    isBinary = false;
                    message = text;
                    messageConverted = true;
                }
            } catch (e) {
                // Not valid UTF-8, ignore
            }
        }
        
        if (session._all_msg_count <= 100) {
            const msgType = typeof message;
            const msgLen = Buffer.isBuffer(message) ? message.length : (typeof message === 'string' ? message.length : 'unknown');
            const preview = typeof message === 'string' ? message.substring(0, 150) : (Buffer.isBuffer(message) && message.length > 0 ? 'BINARY' : 'EMPTY');
            console.log(`[WebSocket] Message #${session._all_msg_count} from ${sessionId} (${mode}): isBinary=${isBinary}, type=${msgType}, length=${msgLen}, preview=${preview}, converted=${messageConverted}`);
        }
        
        try {
            
            // If message was converted from binary to text, skip binary handler
            if (messageConverted) {
                // Message was converted - it's now text, skip to text handler below
                // Don't set isBinary = true
            } else if (Buffer.isBuffer(message)) {
                // Ensure message is treated as binary if it's a Buffer (and not converted)
                isBinary = true;
            }
            
            // Handle binary frames (images, large data)
            // Skip if message was converted to text
            if (!messageConverted && (isBinary || Buffer.isBuffer(message))) {
                // OPTIMIZATION: Binary protocol [type:1byte][length:4bytes][data:bytes]
                if (message.length < 5) {
                    // Small message - might be text, check it
                    if (Buffer.isBuffer(message)) {
                        try {
                            const text = message.toString('utf-8');
                            if (text.startsWith('{"type":"cursor"') || text.startsWith('{"type": "cursor"')) {
                                console.log(`[CURSOR-BINARY-SMALL] Found cursor in small binary message from ${sessionId} (${mode}): ${text}`);
                                // Convert and process as text
                                isBinary = false;
                                message = text;
                                messageConverted = true;
                                // Fall through to text handler
                            } else {
                                return; // Invalid small binary message
                            }
                        } catch (e) {
                            return; // Invalid message
                        }
                    } else {
                        return; // Invalid message
                    }
                }
                
                const typeByte = message[0];
                const dataLength = message.readUInt32BE(1);
                const data = message.slice(5, 5 + dataLength);
                
                // Log ALL binary messages for first few to debug cursor issue
                if (!session._binary_msg_count) session._binary_msg_count = 0;
                session._binary_msg_count++;
                if (session._binary_msg_count <= 10) {
                    console.log(`[DEBUG] Binary message #${session._binary_msg_count} from ${sessionId} (${mode}): typeByte=0x${typeByte.toString(16).padStart(2, '0')}, dataLength=${dataLength}, messageLength=${message.length}`);
                }
                
                if (data.length !== dataLength) {
                    if (DEBUG || session._binary_msg_count <= 10) {
                        console.error(`[WebSocket] Invalid binary message length: expected ${dataLength}, got ${data.length} from ${sessionId} (${mode})`);
                    }
                    return;
                }
                
                const dataType = typeByte === 0x01 ? 'frame' : typeByte === 0x02 ? 'input' : typeByte === 0x04 ? 'cursor' : null;
                if (!dataType) {
                    // Log unknown type bytes for debugging (first few only)
                    if (!session._unknown_type_count) session._unknown_type_count = 0;
                    session._unknown_type_count++;
                    if (session._unknown_type_count <= 5) {
                        console.log(`[DEBUG] Unknown message type byte: 0x${typeByte.toString(16).padStart(2, '0')} from ${sessionId} (${mode}), length=${message.length}`);
                    }
                    return;
                }
                
                // Log input forwarding for debugging
                if (dataType === 'input') {
                    if (!session._input_count) session._input_count = 0;
                    session._input_count++;
                    if (session._input_count <= 5 || session._input_count % 20 === 0) {
                        console.log(`[INPUT] Received ${dataType} from ${sessionId} (${mode}), peerWs=${session.peerWs ? 'set' : 'null'}, peerId=${session.peerId || 'null'}`);
                    }
                }
                
                // Log cursor forwarding for debugging
                if (dataType === 'cursor') {
                    if (!session._cursor_count) session._cursor_count = 0;
                    session._cursor_count++;
                    // Always log first 10 cursor messages for debugging
                    if (session._cursor_count <= 10) {
                        console.log(`[CURSOR] Received cursor message #${session._cursor_count} from ${sessionId} (${mode}), data length=${data.length}, peerWs=${session.peerWs ? 'set' : 'null'}, peerId=${session.peerId || 'null'}`);
                    }
                    try {
                        const cursorData = JSON.parse(data.toString('utf-8'));
                        if (session._cursor_count <= 10 || session._cursor_count % 30 === 0) {
                            console.log(`[CURSOR] Parsed cursor from ${sessionId} (${mode}): (${cursorData.x}, ${cursorData.y}), peerWs=${session.peerWs ? 'set' : 'null'}, peerId=${session.peerId || 'null'}`);
                        }
                    } catch (e) {
                        // Always log parse errors for first 10
                        console.log(`[CURSOR] Parse error for cursor #${session._cursor_count} from ${sessionId} (${mode}): ${e.message}, data preview: ${data.toString('utf-8').substring(0, 100)}`);
                    }
                }
                
                // Check if peer is linked directly
                if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                    // OPTIMIZATION: Direct forwarding - no logging for performance
                    if (dataType === 'input' && (session._input_count <= 5 || session._input_count % 20 === 0)) {
                        console.log(`[INPUT] Forwarding directly via peerWs to peer`);
                    }
                    if (dataType === 'cursor' && (session._cursor_count <= 5 || session._cursor_count % 30 === 0)) {
                        console.log(`[CURSOR] Forwarding directly via peerWs to peer`);
                    }
                    session.peerWs.send(message, { binary: true });
                } else {
                    // Peer not linked yet - use session.peerId (set by getPeerId callback)
                    const targetPeerId = session.peerId;
                    if (targetPeerId) {
                        // Try to find peer and link them
                        const peerSession = activeSessions.get(targetPeerId);
                        if (peerSession && peerSession.mode !== mode && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
                            // Found peer! Link them now
                            session.peerWs = peerSession.ws;
                            peerSession.peerWs = session.ws;
                            if (PEER_DEBUG || dataType === 'input') {
                                console.log(`[WebSocket] *** Late peer linking: ${sessionId} (${mode}) <-> ${targetPeerId} (${peerSession.mode}) ***`);
                            }
                            // Forward frame directly
                            if (dataType === 'input' && (session._input_count <= 5 || session._input_count % 20 === 0)) {
                                console.log(`[INPUT] Forwarding via late-linked peerWs`);
                            }
                            peerSession.ws.send(message, { binary: true });
                        } else {
                            // Store in buffer for later forwarding
                            if (dataType === 'input' && (session._input_count <= 5 || session._input_count % 20 === 0)) {
                                console.log(`[INPUT] Peer not found, buffering: peerSession=${peerSession ? 'exists' : 'null'}, mode=${peerSession?.mode}, ws=${peerSession?.ws ? 'exists' : 'null'}, readyState=${peerSession?.ws?.readyState}`);
                            }
                            forwardToPeer(targetPeerId, dataType, data);
                        }
                    } else {
                        // No peerId yet - frames will be lost until peerId is retrieved
                        if (PEER_DEBUG || dataType === 'input') {
                            console.log(`[WebSocket] No peerId for session ${sessionId} (${mode}) - cannot forward ${dataType} (waiting for peerId)`);
                        }
                    }
                }
            }
            
            // Handle text messages (including converted cursor messages)
            // This includes: 1) Native text messages, 2) Converted binary->text cursor messages
            if (!isBinary || messageConverted || typeof message === 'string') {
                // TEXT/JSON protocol (text messages)
                // Note: message might have been converted from binary above (cursor detection)
                // Log that we're handling a text message
                if (session._all_msg_count <= 50) {
                    console.log(`[WebSocket] Processing TEXT message from ${sessionId} (${mode}): isBinary=${isBinary}, converted=${messageConverted}, message type=${typeof message}, preview=${(typeof message === 'string' ? message : message.toString('utf-8')).substring(0, 200)}`);
                }
                try {
                    // Ensure message is a string before parsing
                    // (message might have been converted from binary in the cursor detection above)
                    const messageStr = typeof message === 'string' ? message : message.toString('utf-8');
                    const data = JSON.parse(messageStr);
                    
                    // Handle cursor position messages (sent as text JSON)
                    if (data.type === 'cursor') {
                        if (!session._cursor_count) session._cursor_count = 0;
                        session._cursor_count++;
                        
                        if (session._cursor_count <= 10 || session._cursor_count % 30 === 0) {
                            console.log(`[CURSOR] Received cursor from ${sessionId} (${mode}): (${data.x}, ${data.y}), peerWs=${session.peerWs ? 'set' : 'null'}, peerId=${session.peerId || 'null'}`);
                        }
                        
                        // Forward cursor to peer
                        if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                            // Forward as text JSON (same format)
                            if (session._cursor_count <= 5 || session._cursor_count % 30 === 0) {
                                console.log(`[CURSOR] Forwarding cursor to peer via peerWs`);
                            }
                            session.peerWs.send(message.toString(), { binary: false });
                        } else {
                            // Peer not connected - try to find and link
                            const targetPeerId = session.peerId;
                            if (targetPeerId) {
                                const peerSession = activeSessions.get(targetPeerId);
                                if (peerSession && peerSession.mode !== mode && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
                                    // Link and forward
                                    session.peerWs = peerSession.ws;
                                    peerSession.peerWs = session.ws;
                                    peerSession.ws.send(message.toString(), { binary: false });
                                } else {
                                    // Buffer for later (cursor positions are time-sensitive, but buffer anyway)
                                    if (session._cursor_count <= 5) {
                                        console.log(`[CURSOR] Peer not found, cannot forward cursor`);
                                    }
                                }
                            }
                        }
                        return; // Cursor handled
                    }
                    
                    // Handle legacy JSON protocol (backward compatibility)
                    if (data.type === 'send_frame' || data.type === 'send_input') {
                        if (!peerId) {
                            ws.send(JSON.stringify({ type: 'error', message: 'Peer not connected yet' }));
                            return;
                        }
                        
                        const relayType = data.type === 'send_frame' ? 'frame' : 'input';
                        
                        // Convert base64 to buffer if needed
                        let frameData;
                        if (typeof data.data === 'string') {
                            frameData = Buffer.from(data.data, 'base64');
                        } else {
                            frameData = Buffer.from(data.data);
                        }
                        
                        // Forward directly to peer if connected
                        if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                            // Send as binary for better performance
                            const typeByte = relayType === 'frame' ? 0x01 : relayType === 'input' ? 0x02 : relayType === 'cursor' ? 0x04 : 0x01;
                            const lengthBuffer = Buffer.allocUnsafe(4);
                            lengthBuffer.writeUInt32BE(frameData.length, 0);
                            const binaryMessage = Buffer.concat([
                                Buffer.from([typeByte]),
                                lengthBuffer,
                                frameData
                            ]);
                            session.peerWs.send(binaryMessage, { binary: true });
                        } else {
                            // Store in buffer
                            forwardToPeer(peerId, relayType, frameData);
                        }
                        
                        // Send acknowledgment
                        ws.send(JSON.stringify({ type: 'ack', success: true }));
                    }
                } catch (parseError) {
                    // Not valid JSON - might be a text message we don't recognize
                    // But don't log if it's a binary frame that was misclassified
                    if (Buffer.isBuffer(message) && message.length > 0 && message[0] === 0x01) {
                        // This is actually a binary frame (starts with 0x01 = frame type)
                        // Don't log as error - it was misclassified as text
                        return;
                    }
                    if (session._all_msg_count <= 10) {
                        console.log(`[WebSocket] Failed to parse text message as JSON from ${sessionId} (${mode}): ${parseError.message}, isBinary=${isBinary}, message type=${typeof message}, preview: ${typeof message === 'string' ? message.substring(0, 100) : (Buffer.isBuffer(message) ? 'BINARY' : 'UNKNOWN')}`);
                    }
                }
            }
        } catch (error) {
            // Suppress UTF-8 validation errors and JSON parsing errors for binary frames
            if (error.code === 'WS_ERR_INVALID_UTF8' || 
                error.message?.includes('Invalid UTF-8') ||
                error.message?.includes('Unexpected token') ||
                (error.name === 'SyntaxError' && error.message?.includes('JSON'))) {
                // This is expected for binary frames - ignore it silently
                // The message should still be passed to our handler with isBinary=true
                return;
            }
            // Only log non-suppressed errors
            console.error('[WebSocket] Message error (not suppressed):', error.message || error);
        }
    });
    
    
    // Handle connection close - prevent closing due to UTF-8 validation errors
    let lastError = null;
    ws.on('error', (error) => {
        lastError = error;
        // Suppress UTF-8 validation errors and JSON parsing errors for binary frames
        if (error.code === 'WS_ERR_INVALID_UTF8' || 
            error.message?.includes('Invalid UTF-8') ||
            error.message?.includes('invalid UTF-8 sequence') ||
            error.message?.includes('Unexpected token') ||
            (error.name === 'SyntaxError' && error.message?.includes('JSON'))) {
            // Expected for binary frames - don't log as error
            if (DEBUG) console.log(`[WebSocket] Suppressed validation error for binary frame (expected): ${error.message}`);
            // Don't close connection on these errors
            return;
        }
        if (DEBUG) console.error('[WebSocket] Connection error:', error);
    });
    
    ws.on('close', (code, reason) => {
        // If close was due to UTF-8 validation error, try to prevent it
        if (code === 1007 && lastError && 
            (lastError.code === 'WS_ERR_INVALID_UTF8' || 
             lastError.message?.includes('Invalid UTF-8'))) {
            if (DEBUG) console.log(`[WebSocket] Ignoring close due to UTF-8 validation (expected for binary frames)`);
            // Don't remove session on UTF-8 validation closes
            return;
        }
        if (DEBUG) console.log(`[WebSocket] Connection closed: ${sessionId}, code=${code}`);
        activeSessions.delete(sessionId);
        
        // OPTIMIZATION: Clean up peer lookup map immediately
        if (peerId) {
            sessionsByPeerId.delete(peerId);
            // Clean up buffer immediately (don't wait 5 seconds)
            frameBuffers.delete(peerId);
        }
        
        // Clean up peer reference
        if (session.peerWs) {
            for (const [otherSessionId, otherSession] of activeSessions.entries()) {
                if (otherSession.peerWs === ws) {
                    otherSession.peerWs = null;
                    break;
                }
            }
        }
    });
    
    // Send connection confirmation
    ws.send(JSON.stringify({ type: 'connected', session_id: sessionId }));
});

// Start server - SSL/WSS only
server.listen(SSL_PORT, () => {
    console.log(`[WebSocket] OPTIMIZED Relay server listening on port ${SSL_PORT} (WSS only)`);
    console.log(`[WebSocket] Features: In-memory storage, direct forwarding, binary frames`);
    console.log(`[WebSocket] Storage path: ${RELAY_STORAGE_PATH} (fallback only)`);
    console.log(`[WebSocket] PHP API URL: ${PHP_API_URL}`);
    console.log(`[WebSocket] SSL/WSS required - connect using: wss://sharefast.zip:${SSL_PORT}`);
    console.log(`[WebSocket] MAX_BUFFER_SIZE: ${MAX_BUFFER_SIZE} frames`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[WebSocket] Shutting down...');
    wss.close(() => {
        server.close(() => {
            process.exit(0);
        });
    });
});

// Memory monitoring (optional - for debugging)
if (process.env.DEBUG_MEMORY === 'true') {
    setInterval(() => {
        const used = process.memoryUsage();
        const sessions = activeSessions.size;
        const buffers = Array.from(frameBuffers.values()).reduce((sum, buf) => sum + buf.length, 0);
        const totalBufferSize = Array.from(frameBuffers.values()).reduce((sum, buf) => {
            return sum + buf.reduce((frameSum, frame) => frameSum + (frame.data ? frame.data.length : 0), 0);
        }, 0);
        console.log(`[Memory] Sessions: ${sessions}, Buffered frames: ${buffers}, Buffer size: ${Math.round(totalBufferSize / 1024 / 1024)}MB, Heap: ${Math.round(used.heapUsed / 1024 / 1024)}MB`);
    }, 10000); // Every 10 seconds
}
