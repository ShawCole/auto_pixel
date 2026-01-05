import mysql from 'mysql2/promise';

const dbConfig = {
    host: '34.26.61.148',
    user: 'root',
    password: 'AccuPoint01!',
    database: 'pixel'
};

async function migrate() {
    const connection = await mysql.createConnection(dbConfig);
    try {
        console.log('Checking pixel_sheets columns...');
        const [rows] = await connection.execute('DESCRIBE pixel_sheets');
        const columns = rows.map(r => r.Field);

        if (!columns.includes('segment_name')) {
            console.log('Adding segment_name column...');
            await connection.execute('ALTER TABLE pixel_sheets ADD COLUMN segment_name VARCHAR(255) DEFAULT NULL AFTER client_website');
            console.log('✅ segment_name added');
        } else {
            console.log('ℹ️ segment_name already exists');
        }

        if (!columns.includes('segment_api')) {
            console.log('Adding segment_api column...');
            await connection.execute('ALTER TABLE pixel_sheets ADD COLUMN segment_api TEXT DEFAULT NULL AFTER segment_name');
            console.log('✅ segment_api added');
        } else {
            console.log('ℹ️ segment_api already exists');
        }

    } catch (error) {
        console.error('❌ Migration failed:', error);
    } finally {
        await connection.end();
    }
}

migrate();
