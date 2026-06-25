const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const fs = require('fs');

const dataDir = path.resolve(__dirname, 'data');
if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
}

const dbPath = path.join(dataDir, 'manager.sqlite');
const db = new sqlite3.Database(dbPath, (err) => {
    if (err) {
        console.error('Error opening database', err.message);
    } else {
        db.run(`CREATE TABLE IF NOT EXISTS instances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rfc TEXT UNIQUE NOT NULL,
            company TEXT NOT NULL,
            port INTEGER NOT NULL,
            install_path TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_backup DATETIME,
            status TEXT DEFAULT 'online'
        )`);

        db.run(`CREATE TABLE IF NOT EXISTS manager_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            rfc TEXT,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`);

        db.run(`CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )`, () => {
            // Populate defaults if empty
            const defaults = {
                'SERVER_IP': '192.168.1.10',
                'BASE_PATH': '/home/aplicaciones/pui/clientes',
                'DEFAULT_API_USER': 'PUI',
                'BACKUP_PATH': '/home/aplicaciones/pui/backups',
                'RETENTION_DAYS': '30'
            };
            const stmt = db.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
            for (const [key, value] of Object.entries(defaults)) {
                stmt.run([key, value]);
            }
            stmt.finalize();
        });
    }
});

module.exports = db;
