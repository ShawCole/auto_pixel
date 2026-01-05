module.exports = {
  apps: [{
    name: 'auto-pixel-api',
    script: 'dist/index.js',
    env: {
      DB_HOST: '34.26.61.148',
      DB_USER: 'root',
      DB_PASS: 'AccuPoint01!',
      DB_PORT: '3306',
      DB_NAME: 'pixel',          // <--- ADD THIS
      DB_DATABASE: 'pixel',      // <--- ADD THIS
      TEMPLATE_DB: 'template',
      TEMPLATE_TABLE: 'superpixel_resolution_log',
      TEMPLATE_TABLE_2: 'unique_visitors',
      AUDLAB_USERNAME: 'shaw@accupointsolutions.com',
      AUDLAB_PASSWORD: 'AccuPoint01!',
      PORT: '4000',
      NODE_ENV: 'production'
    }
  }]
}
