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
    console.error(errorMsg);
    process.exit(1);
}

// Load SSL certificates
const sslOptions = {
    cert: fs.readFileSync(SSL_CERT_PATH),
    key: fs.readFileSync(SSL_KEY_PATH)
};

// In-memory storage for active sessions (O(1) lookup)
const activeSessions = new Map(); // sessionId -> { ws, mode, peerId, peerWs, buffer }
const sessionsByPeerId = new Map(); // peerId -> sessionId (for O(1) peer lookup)

// Frame buffer (in-memory only, no file I/O for active sessions)
const MAX_BUFFER_SIZE = 10; // Reduced from 60 for lower memory usage
const frameBuffers = new Map(); // sessionId -> [{ type, data, timestamp }, ...]

/**
 * Get peer_id from PHP API
 */
async function getPeerId(sessionId, code) {
    try {
        const url = `${PHP_API_URL}get_peer_id.php?session_id=${encodeURIComponent(sessionId)}&code=${encodeURIComponent(code)}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.peer_id || null;
    } catch (error) {
        if (DEBUG) console.error(`[getPeerId] Error: ${error.message}`);
        return null;
    }
}

/**
 * Forward data to peer (buffers if peer not connected)
 */
function forwardToPeer(targetPeerId, dataType, data) {
    if (!targetPeerId) return;
    
    const peerSession = activeSessions.get(targetPeerId);
    if (peerSession && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
        // Peer is connected - forward directly
        const typeByte = dataType === 'frame' ? 0x01 : dataType === 'input' ? 0x02 : dataType === 'cursor' ? 0x04 : 0x01;
        const lengthBuffer = Buffer.allocUnsafe(4);
        lengthBuffer.writeUInt32BE(data.length, 0);
        const binaryMessage = Buffer.concat([
            Buffer.from([typeByte]),
            lengthBuffer,
            data
        ]);
        peerSession.ws.send(binaryMessage, { binary: true });
        return;
    }
    
    // Peer not connected - buffer for later
    if (!frameBuffers.has(targetPeerId)) {
        frameBuffers.set(targetPeerId, []);
    }
    const buffer = frameBuffers.get(targetPeerId);
    buffer.push({ type: dataType, data, timestamp: Date.now() });
    
    // Limit buffer size
    if (buffer.length > MAX_BUFFER_SIZE) {
        buffer.shift(); // Remove oldest frame
    }
}

/**
 * Flush buffered frames to peer
 */
function flushBufferToPeer(targetPeerId, peerWs) {
    if (!frameBuffers.has(targetPeerId)) return;
    
    const buffer = frameBuffers.get(targetPeerId);
    while (buffer.length > 0 && peerWs.readyState === WebSocket.OPEN) {
        const item = buffer.shift();
        const typeByte = item.type === 'frame' ? 0x01 : item.type === 'input' ? 0x02 : item.type === 'cursor' ? 0x04 : 0x01;
        const lengthBuffer = Buffer.allocUnsafe(4);
        lengthBuffer.writeUInt32BE(item.data.length, 0);
        const binaryMessage = Buffer.concat([
            Buffer.from([typeByte]),
            lengthBuffer,
            item.data
        ]);
        peerWs.send(binaryMessage, { binary: true });
    }
    
    // Clear buffer after flushing
    frameBuffers.delete(targetPeerId);
}

// Create HTTPS server
const server = https.createServer(sslOptions);

// Create WebSocket server
const wss = new WebSocket.Server({
    server,
    // NAT-friendly: Disable compression entirely to avoid packet fragmentation
    perMessageDeflate: false
});

wss.on('connection', (ws, req) => {
    // Extract session_id, code, and mode from query string
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
    
    // REBUILT: Simplified message handler for binary and text messages
    console.log(`[WebSocket] Attaching message handler for ${sessionId} (${mode})`);
    
    // Initialize message counters for logging
    if (!session._msg_count) session._msg_count = 0;
    
    ws.on('message', (message, isBinary) => {
        session._msg_count++;
        
        // Log first 10 messages to verify handler is working
        if (session._msg_count <= 10) {
            const msgType = typeof message;
            const msgLen = Buffer.isBuffer(message) ? message.length : (typeof message === 'string' ? message.length : 'unknown');
            console.log(`[MSG] #${session._msg_count} from ${sessionId} (${mode}): isBinary=${isBinary}, type=${msgType}, len=${msgLen}`);
        }
        
        try {
            // Handle binary messages (frames, input, cursor)
            if (isBinary || Buffer.isBuffer(message)) {
                // Ensure message is a Buffer
                const buffer = Buffer.isBuffer(message) ? message : Buffer.from(message);
                
                if (buffer.length < 5) {
                    // Too short to be valid
                    if (session._msg_count <= 5) {
                        console.log(`[MSG] Invalid binary message: too short (${buffer.length} bytes)`);
                    }
                    return;
                }
                
                // Parse binary protocol: [type:1byte][length:4bytes][data:bytes]
                // Or new format: [type:0x01][flags:1byte][metadata_length:2bytes][metadata:bytes][frame_length:4bytes][frame_data:bytes]
                const typeByte = buffer[0];
                let data, cursorX, cursorY;
                
                if (typeByte === 0x01) {
                    // Frame - check if new format with metadata
                    if (buffer.length >= 8 && buffer[1] <= 1) {
                        // New format: [type:0x01][flags:1byte][metadata_length:2bytes][metadata:bytes][frame_length:4bytes][frame_data:bytes]
                        const flags = buffer[1];
                        const metadataLength = buffer.readUInt16BE(2);
                        let offset = 4;
                        
                        // Extract cursor from metadata if present
                        if (flags & 0x01 && metadataLength >= 8) {
                            cursorX = buffer.readUInt32BE(offset);
                            cursorY = buffer.readUInt32BE(offset + 4);
                            offset += 8;
                        } else {
                            offset += metadataLength;
                        }
                        
                        // Extract frame data
                        const frameLength = buffer.readUInt32BE(offset);
                        offset += 4;
                        data = buffer.slice(offset, offset + frameLength);
                    } else {
                        // Old format: [type:0x01][length:4bytes][data:bytes]
                        const dataLength = buffer.readUInt32BE(1);
                        data = buffer.slice(5, 5 + dataLength);
                        cursorX = null;
                        cursorY = null;
                    }
                    
                    // If cursor was extracted from frame, send it separately
                    if (cursorX !== null && cursorY !== null && mode === 'client') {
                        const cursorData = JSON.stringify({ type: 'cursor', x: cursorX, y: cursorY });
                        const cursorMessage = Buffer.concat([
                            Buffer.from([0x04]),
                            Buffer.allocUnsafe(4),
                            Buffer.from(cursorData, 'utf-8')
                        ]);
                        cursorMessage.writeUInt32BE(cursorData.length, 1);
                        
                        if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                            session.peerWs.send(cursorMessage, { binary: true });
                        }
                    }
                } else {
                    // Non-frame message: [type:1byte][length:4bytes][data:bytes]
                    const dataLength = buffer.readUInt32BE(1);
                    data = buffer.slice(5, 5 + dataLength);
                }
                
                if (!data || data.length === 0) {
                    if (session._msg_count <= 5) {
                        console.log(`[MSG] Invalid binary message: empty data`);
                    }
                    return;
                }
                
                const dataType = typeByte === 0x01 ? 'frame' : typeByte === 0x02 ? 'input' : typeByte === 0x04 ? 'cursor' : null;
                if (!dataType) {
                    if (session._msg_count <= 5) {
                        console.log(`[MSG] Unknown message type: 0x${typeByte.toString(16).padStart(2, '0')}`);
                    }
                    return;
                }
                
                // Forward to peer
                if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                    // Direct forwarding
                    session.peerWs.send(buffer, { binary: true });
                } else {
                    // Try to find peer
                    const targetPeerId = session.peerId;
                    if (targetPeerId) {
                        const peerSession = activeSessions.get(targetPeerId);
                        if (peerSession && peerSession.mode !== mode && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
                            // Link and forward
                            session.peerWs = peerSession.ws;
                            peerSession.peerWs = session.ws;
                            peerSession.ws.send(buffer, { binary: true });
                        } else {
                            // Buffer for later
                            forwardToPeer(targetPeerId, dataType, data);
                        }
                    }
                }
            } else {
                // Handle text messages (JSON)
                const messageStr = typeof message === 'string' ? message : message.toString('utf-8');
                
                try {
                    const data = JSON.parse(messageStr);
                    
                    // Handle cursor position messages
                    if (data.type === 'cursor') {
                        if (session._msg_count <= 10) {
                            console.log(`[CURSOR] Received cursor from ${sessionId} (${mode}): (${data.x}, ${data.y})`);
                        }
                        
                        // Forward to peer
                        if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                            session.peerWs.send(messageStr, { binary: false });
                        } else {
                            const targetPeerId = session.peerId;
                            if (targetPeerId) {
                                const peerSession = activeSessions.get(targetPeerId);
                                if (peerSession && peerSession.mode !== mode && peerSession.ws && peerSession.ws.readyState === WebSocket.OPEN) {
                                    session.peerWs = peerSession.ws;
                                    peerSession.peerWs = session.ws;
                                    peerSession.ws.send(messageStr, { binary: false });
                                }
                            }
                        }
                        return;
                    }
                    
                    // Handle legacy JSON protocol
                    if (data.type === 'send_frame' || data.type === 'send_input') {
                        if (!peerId) {
                            ws.send(JSON.stringify({ type: 'error', message: 'Peer not connected yet' }));
                            return;
                        }
                        
                        const relayType = data.type === 'send_frame' ? 'frame' : 'input';
                        let frameData;
                        if (typeof data.data === 'string') {
                            frameData = Buffer.from(data.data, 'base64');
                        } else {
                            frameData = Buffer.from(data.data);
                        }
                        
                        if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                            const typeByte = relayType === 'frame' ? 0x01 : 0x02;
                            const lengthBuffer = Buffer.allocUnsafe(4);
                            lengthBuffer.writeUInt32BE(frameData.length, 0);
                            const binaryMessage = Buffer.concat([
                                Buffer.from([typeByte]),
                                lengthBuffer,
                                frameData
                            ]);
                            session.peerWs.send(binaryMessage, { binary: true });
                        } else {
                            forwardToPeer(peerId, relayType, frameData);
                        }
                        
                        ws.send(JSON.stringify({ type: 'ack', success: true }));
                    }
                } catch (parseError) {
                    if (session._msg_count <= 5) {
                        console.log(`[MSG] Failed to parse text message: ${parseError.message}`);
                    }
                }
            }
        } catch (error) {
            // Suppress UTF-8 validation errors for binary frames
            if (error.code === 'WS_ERR_INVALID_UTF8' || 
                error.message?.includes('Invalid UTF-8') ||
                error.message?.includes('Unexpected token')) {
                return;
            }
            console.error(`[WebSocket] Message error: ${error.message}`);
        }
    });
    
    // Send connection confirmation
    ws.send(JSON.stringify({ type: 'connected', session_id: sessionId }));
    
    // Handle connection close
    ws.on('close', () => {
        if (PEER_DEBUG) console.log(`[WebSocket] Connection closed: ${sessionId} (${mode})`);
        
        // Unlink peer if connected
        if (session.peerWs) {
            const peerSession = activeSessions.get(session.peerId);
            if (peerSession) {
                peerSession.peerWs = null;
            }
        }
        
        // Remove from active sessions
        activeSessions.delete(sessionId);
        sessionsByPeerId.delete(sessionId);
        
        // Clear buffer
        frameBuffers.delete(sessionId);
    });
    
    // Handle errors
    ws.on('error', (error) => {
        // Suppress UTF-8 validation errors for binary frames
        if (error.code === 'WS_ERR_INVALID_UTF8' || 
            error.message?.includes('Invalid UTF-8') ||
            error.message?.includes('invalid UTF-8 sequence') ||
            error.message?.includes('Unexpected token')) {
            return;
        }
        if (DEBUG) console.error(`[WebSocket] Connection error: ${error.message}`);
    });
});

// Start server
server.listen(SSL_PORT, () => {
    console.log(`[WebSocket] SSL enabled - using certificates:`);
    console.log(`  Certificate: ${SSL_CERT_PATH}`);
    console.log(`  Key: ${SSL_KEY_PATH}`);
    console.log(`[WebSocket] OPTIMIZED Relay server listening on port ${SSL_PORT} (WSS only)`);
    console.log(`[WebSocket] Features: In-memory storage, direct forwarding, binary frames`);
    console.log(`[WebSocket] Storage path: ${RELAY_STORAGE_PATH} (fallback only)`);
    console.log(`[WebSocket] PHP API URL: ${PHP_API_URL}`);
    console.log(`[WebSocket] SSL/WSS required - connect using: wss://sharefast.zip:${SSL_PORT}`);
    console.log(`[WebSocket] MAX_BUFFER_SIZE: ${MAX_BUFFER_SIZE} frames`);
});
