const express = require('express');
const bodyParser = require('body-parser');
const app = express();

// Parse raw body for debugging
app.use(bodyParser.raw({ type: '*/*', limit: '10mb' }));

// Middleware to log all requests
app.use((req, res, next) => {
    console.log('\n========== NEW REQUEST ==========');
    console.log(`[${new Date().toISOString()}] ${req.method} ${req.url}`);
    console.log('\n--- HEADERS ---');
    console.log(JSON.stringify(req.headers, null, 2));

    console.log('\n--- QUERY PARAMS ---');
    console.log(JSON.stringify(req.query, null, 2));

    if (req.body) {
        console.log('\n--- RAW BODY ---');
        const bodyStr = req.body.toString();
        console.log(bodyStr);

        // Try to parse as JSON
        try {
            const parsed = JSON.parse(bodyStr);
            console.log('\n--- PARSED JSON ---');
            console.log(JSON.stringify(parsed, null, 2));
        } catch (e) {
            console.log('(Body is not valid JSON)');
        }
    }

    console.log('\n--- CLIENT INFO ---');
    console.log(`IP: ${req.ip}`);
    console.log(`IPs (from proxy): ${req.ips.join(', ') || 'none'}`);
    console.log(`User-Agent: ${req.headers['user-agent'] || 'none'}`);

    next();
});

// Handle all requests
app.all('*', (req, res) => {
    const response = {
        status: 'success',
        message: 'Test webhook received',
        timestamp: new Date().toISOString(),
        method: req.method,
        path: req.path,
        query: req.query,
        headers_received: Object.keys(req.headers),
        your_ip: req.ip
    };

    console.log('\n--- SENDING RESPONSE ---');
    console.log(JSON.stringify(response, null, 2));
    console.log('================================\n');

    res.json(response);
});

const PORT = process.env.PORT || 3001;
app.listen(PORT, () => {
    console.log(`🚀 Webhook test server listening on port ${PORT}`);
    console.log(`📝 All incoming requests will be logged in detail`);
    console.log(`\nYou can test with:`);
    console.log(`curl -X GET http://localhost:${PORT}/pixel_import.php?client=test`);
    console.log(`\nOr use ngrok to expose it publicly:`);
    console.log(`ngrok http ${PORT}`);
}); 