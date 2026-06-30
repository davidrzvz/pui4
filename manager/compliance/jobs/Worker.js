const db = require('../../database');
const AuditEngine = require('../engine/AuditEngine');
const ScannerManager = require('../scanners/ScannerManager');
const StorageManager = require('../storage/StorageManager');

class Worker {
    constructor(queue) {
        this.queue = queue;
        this.isRunning = false;
        
        // Initialize dependencies
        const storageManager = new StorageManager();
        const scannerManager = new ScannerManager();
        this.auditEngine = new AuditEngine(scannerManager, storageManager);
    }

    start() {
        if (this.isRunning) return;
        this.isRunning = true;
        this._loop();
    }

    stop() {
        this.isRunning = false;
    }

    async _loop() {
        while (this.isRunning) {
            try {
                const job = await this.queue.pop();
                if (job) {
                    await this._processJob(job);
                } else {
                    // No jobs pending, wait a bit before checking again
                    await new Promise(resolve => setTimeout(resolve, 3000));
                }
            } catch (error) {
                console.error("Worker error:", error);
                await new Promise(resolve => setTimeout(resolve, 5000)); // Backoff
            }
        }
    }

    async _processJob(job) {
        try {
            await this.queue.updateStatus(job.id, 'Running', 10);
            
            // Retrieve audit details from DB
            const audit = await this._getAuditDetails(job.audit_id);
            if (!audit) throw new Error("Audit record not found");

            // Ensure audit directory is created early using the original date
            const storageManager = new (require('../storage/StorageManager'))();
            storageManager.getOrCreateAuditDirectory(audit.id, new Date(audit.date));

            // Execute the appropriate scanners via ScannerManager based on profile/type
            // For now, this is a skeleton simulation.
            // In the full implementation, ScannerManager will instantiate the correct Adapters.
            
            await this.queue.updateStatus(job.id, 'Running', 50);

            // Simulate work
            await new Promise(resolve => setTimeout(resolve, 5000));

            // Generate reports
            const ReportGenerator = require('../reports/ReportGenerator');
            const reportGen = new ReportGenerator(storageManager);
            
            const auditData = {
                id: audit.id,
                date: audit.date,
                profile: audit.profile,
                vulnerabilities_count: audit.vulnerabilities_count || 0,
                status: 'Finished',
                toolsUsed: ['Simulated-Tool'],
                toolVersions: { 'Simulated-Tool': '1.0' },
                durationMs: 5000
            };
            
            await reportGen.generateReports(audit.id, auditData, new Date(audit.date));

            // Mark as finished
            await this.queue.updateStatus(job.id, 'Finished', 100);
            
            // Update Audit record status
            await this._updateAuditStatus(audit.id, 'finished');
            
        } catch (error) {
            console.error(`Error processing job ${job.id}:`, error);
            await this.queue.updateStatus(job.id, 'Failed', 0);
            await this._updateAuditStatus(job.audit_id, 'failed');
            
            // Generate failed reports
            try {
                const storageManager = new (require('../storage/StorageManager'))();
                const ReportGenerator = require('../reports/ReportGenerator');
                const reportGen = new ReportGenerator(storageManager);
                const audit = await this._getAuditDetails(job.audit_id);
                if (audit) {
                    const auditData = {
                        id: audit.id,
                        date: audit.date,
                        profile: audit.profile,
                        vulnerabilities_count: 0,
                        status: 'Failed',
                        toolsUsed: [],
                        toolVersions: {},
                        durationMs: 0
                    };
                    await reportGen.generateReports(audit.id, auditData, new Date(audit.date));
                }
            } catch (e) {
                console.error("Failed to generate error evidence:", e);
            }
        }
    }

    _getAuditDetails(auditId) {
        return new Promise((resolve, reject) => {
            db.get(`SELECT * FROM security_audits WHERE id = ?`, [auditId], (err, row) => {
                if (err) reject(err);
                else resolve(row);
            });
        });
    }

    _updateAuditStatus(auditId, status) {
        return new Promise((resolve, reject) => {
            db.run(`UPDATE security_audits SET status = ? WHERE id = ?`, [status, auditId], (err) => {
                if (err) reject(err);
                else resolve();
            });
        });
    }
}

module.exports = Worker;
