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
    // OPTIMIZATION: Disable compression for lower CPU usage (frames are already JPEG compressed)
    // Keep only minimal compression for small messages
    perMessageDeflate: {
        zlibDeflateOptions: {
            chunkSize: 1024,
            memLevel: 7,
            level: 1  // Reduced from 3 to 1 for lower CPU usage
        },
        zlibInflateOptions: {
            chunkSize: 10 * 1024
        },
        clientNoContextTakeover: true,
        serverNoContextTakeover: true,
        serverMaxWindowBits: 10,
        concurrencyLimit: 5,  // Reduced from 10
        threshold: 10240  // Increased threshold - only compress messages >10KB
    }
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
                const typeByte = dataType === 'frame' ? 0x01 : 0x02; // 0x01=frame, 0x02=input
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
            const typeByte = frame.type === 'frame' ? 0x01 : 0x02;
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
    
    // Verify SSL connection
    if (!req.socket || !req.socket.encrypted) {
        if (DEBUG) console.warn(`[WebSocket] Non-SSL connection attempt rejected from ${req.socket.remoteAddress}`);
        ws.close(1008, 'SSL required. WebSocket connections must use WSS (secure WebSocket).');
        return;
    }
    
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
    ws.on('message', (message, isBinary) => {
        try {
            // Ensure message is treated as binary if it's a Buffer
            if (Buffer.isBuffer(message)) {
                isBinary = true;
            }
            
            // Handle binary frames (images, large data)
            if (isBinary || Buffer.isBuffer(message)) {
                // OPTIMIZATION: Binary protocol [type:1byte][length:4bytes][data:bytes]
                if (message.length < 5) {
                    return; // Invalid message
                }
                
                const typeByte = message[0];
                const dataLength = message.readUInt32BE(1);
                const data = message.slice(5, 5 + dataLength);
                
                if (data.length !== dataLength) {
                    if (DEBUG) console.error(`[WebSocket] Invalid binary message length`);
                    return;
                }
                
                const dataType = typeByte === 0x01 ? 'frame' : typeByte === 0x02 ? 'input' : null;
                if (!dataType) {
                    return;
                }
                
                // Check if peer is linked directly
                if (session.peerWs && session.peerWs.readyState === WebSocket.OPEN) {
                    // OPTIMIZATION: Direct forwarding - no logging for performance
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
                            if (PEER_DEBUG) {
                                console.log(`[WebSocket] *** Late peer linking: ${sessionId} (${mode}) <-> ${targetPeerId} (${peerSession.mode}) ***`);
                            }
                            // Forward frame directly
                            peerSession.ws.send(message, { binary: true });
                        } else {
                            // Store in buffer for later forwarding
                            forwardToPeer(targetPeerId, dataType, data);
                        }
                    } else {
                        // No peerId yet - frames will be lost until peerId is retrieved
                        if (PEER_DEBUG && dataType === 'frame') {
                            console.log(`[WebSocket] No peerId for session ${sessionId} (${mode}) - cannot forward frame (waiting for peerId)`);
                        }
                    }
                }
            } else {
                // JSON protocol (backward compatibility)
                const data = JSON.parse(message.toString());
                
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
                        // Store in buffer
                        forwardToPeer(peerId, relayType, frameData);
                    }
                    
                    // Send acknowledgment
                    ws.send(JSON.stringify({ type: 'ack', success: true }));
                }
            }
        } catch (error) {
            // Suppress UTF-8 validation errors for binary frames
            if (error.code === 'WS_ERR_INVALID_UTF8' || error.message?.includes('Invalid UTF-8')) {
                // This is expected for binary frames - ignore it
                return;
            }
            if (DEBUG) console.error('[WebSocket] Message error:', error);
        }
    });
    
    
    // Handle connection close - prevent closing due to UTF-8 validation errors
    let lastError = null;
    ws.on('error', (error) => {
        lastError = error;
        // Suppress UTF-8 validation errors for binary frames
        if (error.code === 'WS_ERR_INVALID_UTF8' || 
            error.message?.includes('Invalid UTF-8') ||
            error.message?.includes('invalid UTF-8 sequence')) {
            // Expected for binary frames - don't log as error
            if (DEBUG) console.log(`[WebSocket] Suppressed UTF-8 validation error for binary frame (expected)`);
            // Don't close connection on UTF-8 errors
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
