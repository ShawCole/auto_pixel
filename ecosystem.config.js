module.exports = {
  apps: [{
    name: 'auto-pixel-backend',
    script: './dist/index.js',
    cwd: '/opt/auto-pixel/server',
    env_file: './.env',
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: '1G',
    env: {
      NODE_ENV: 'production'
    }
  }]
}
