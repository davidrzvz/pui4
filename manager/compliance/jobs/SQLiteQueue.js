const Queue = require('./Queue');
const db = require('../../database');

class SQLiteQueue extends Queue {
    async push(jobData) {
        return new Promise((resolve, reject) => {
            db.run(
                `INSERT INTO compliance_jobs (audit_id, status) VALUES (?, 'Pending')`,
                [jobData.auditId],
                function(err) {
                    if (err) reject(err);
                    else resolve(this.lastID);
                }
            );
        });
    }

    async pop() {
        return new Promise((resolve, reject) => {
            // Very simple lock-like pop: find oldest pending, update to running, return it.
            db.serialize(() => {
                db.get(`SELECT * FROM compliance_jobs WHERE status = 'Pending' ORDER BY created_at ASC LIMIT 1`, [], (err, row) => {
                    if (err) {
                        return reject(err);
                    }
                    if (!row) {
                        return resolve(null);
                    }
                    
                    db.run(`UPDATE compliance_jobs SET status = 'Running', updated_at = CURRENT_TIMESTAMP WHERE id = ?`, [row.id], (updateErr) => {
                        if (updateErr) return reject(updateErr);
                        resolve(row);
                    });
                });
            });
        });
    }

    async updateStatus(jobId, status, progress = 0) {
        return new Promise((resolve, reject) => {
            db.run(
                `UPDATE compliance_jobs SET status = ?, progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
                [status, progress, jobId],
                function(err) {
                    if (err) reject(err);
                    else resolve(true);
                }
            );
        });
    }
}

module.exports = SQLiteQueue;
