/**
 * Supabase MCP Proxy Server
 * 
 * This proxy server enables MCP clients (Cursor, Claude Code, Windsurf)
 * to connect to Supabase's MCP endpoint through a local Express server.
 * 
 * Environment variables:
 *   - SUPABASE_URL: Supabase URL (default: http://localhost:8000)
 *   - SUPABASE_ANON_KEY: Supabase anon key
 *   - PROXY_PORT: Proxy server port (default: 3000)
 *   - MCP_PATH: MCP path (default: /mcp)
 */

const express = require('express');
const axios = require('axios');
const http = require('http');

const app = express();
const SUPABASE_URL = process.env.SUPABASE_URL || 'http://localhost:8000';
const SUPABASE_ANON_KEY = process.env.SUPABASE_ANON_KEY || '';
const PROXY_PORT = process.env.PROXY_PORT || 3000;
const MCP_PATH = process.env.MCP_PATH || '/mcp';

if (!SUPABASE_ANON_KEY) {
  console.error('ERROR: SUPABASE_ANON_KEY environment variable is required');
  process.exit(1);
}

app.use(express.json({
  strict: false,
  type: '*/*'  // Allow all content types for MCP
}));

// Health check endpoint
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    supabase_url: SUPABASE_URL,
    mcp_path: MCP_PATH
  });
});

// MCP endpoint - handles both SSE and JSON-RPC
app.all('*', async (req, res) => {
  const targetUrl = `${SUPABASE_URL}${MCP_PATH}`;
  
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.url} -> ${targetUrl}`);
  
  try {
    const response = await axios({
      method: req.method,
      url: targetUrl,
      data: req.body,
      headers: {
        'Content-Type': req.get('Content-Type') || 'application/json',
        'apikey': SUPABASE_ANON_KEY,
        // Forward relevant headers
        'Authorization': req.get('Authorization') || '',
        'Accept': req.get('Accept') || '*/*'
      },
      // Stream responses for SSE
      responseType: req.headers.accept && req.headers.accept.includes('text/event-stream') 
        ? 'stream' 
        : 'json',
      // Timeout
      timeout: 30000,
      validateStatus: (status) => status < 500 // Don't throw on 403/404
    });

    // Stream response back for SSE
    if (response.config.responseType === 'stream') {
      response.data.pipe(res);
    } else {
      // JSON response
      res.json(response.data);
    }
    
  } catch (error) {
    console.error('MCP proxy error:', {
      message: error.message,
      code: error.code,
      status: error.response?.status,
      data: error.response?.data
    });
    
    if (error.response) {
      res.status(error.response.status).json({
        error: 'MCP proxy error',
        details: error.response.data
      });
    } else {
      res.status(500).json({
        error: 'MCP proxy connection failed',
        message: error.message
      });
    }
  }
});

app.listen(PROXY_PORT, () => {
  console.log('='.repeat(60));
  console.log('Supabase MCP Proxy Server');
  console.log('='.repeat(60));
  console.log(`Proxy URL:   http://localhost:${PROXY_PORT}`);
  console.log(`Target URL:  ${SUPABASE_URL}${MCP_PATH}`);
  console.log(`MCP Path:    ${MCP_PATH}`);
  console.log('='.repeat(60));
  console.log('Press Ctrl+C to stop');
  console.log('='.repeat(60));
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('Shutting down gracefully...');
  process.exit(0);
});

process.on('SIGINT', () => {
  console.log('Shutting down gracefully...');
  process.exit(0);
});
