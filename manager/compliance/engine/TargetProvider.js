const db = require('../../database');

class TargetProvider {
    /**
     * Retrieve a specific target (instance) by ID.
     */
    static getTarget(targetId) {
        return new Promise((resolve, reject) => {
            db.get('SELECT * FROM instances WHERE id = ?', [targetId], (err, row) => {
                if (err) return reject(err);
                if (!row) return resolve(null);
                resolve({
                    id: row.id,
                    rfc: row.rfc,
                    company: row.company,
                    url: `http://localhost:${row.port}`, // Assuming local proxy/port mapping for now
                    port: row.port,
                    install_path: row.install_path,
                    status: row.status
                });
            });
        });
    }

    /**
     * Get all available targets.
     */
    static getAllTargets() {
        return new Promise((resolve, reject) => {
            db.all('SELECT * FROM instances', [], (err, rows) => {
                if (err) return reject(err);
                resolve(rows.map(row => ({
                    id: row.id,
                    rfc: row.rfc,
                    company: row.company,
                    url: `http://localhost:${row.port}`,
                    port: row.port,
                    install_path: row.install_path,
                    status: row.status
                })));
            });
        });
    }
}

module.exports = TargetProvider;
