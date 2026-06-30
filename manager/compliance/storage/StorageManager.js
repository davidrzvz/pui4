const fs = require('fs');
const path = require('path');

class StorageManager {
    constructor() {
        this.baseDir = path.join(process.cwd(), 'data', 'security');
        if (!fs.existsSync(this.baseDir)) {
            fs.mkdirSync(this.baseDir, { recursive: true });
        }
    }

    getOrCreateAuditDirectory(auditId, dateObj = new Date()) {
        const year = dateObj.getFullYear().toString();
        const month = (dateObj.getMonth() + 1).toString().padStart(2, '0');
        const day = dateObj.getDate().toString().padStart(2, '0');
        
        const auditDirName = `audit-${auditId.toString().padStart(6, '0')}`;
        const fullPath = path.join(this.baseDir, year, month, day, auditDirName);

        if (!fs.existsSync(fullPath)) {
            fs.mkdirSync(fullPath, { recursive: true });
            
            // Create subdirectories
            ['sast', 'sca', 'dast', 'logs', 'evidencias'].forEach(sub => {
                fs.mkdirSync(path.join(fullPath, sub));
            });
            
            // Create initial metadata.json
            const metadata = {
                audit_id: auditId,
                date: dateObj.toISOString(),
                tools: [],
                status: 'running'
            };
            fs.writeFileSync(path.join(fullPath, 'metadata.json'), JSON.stringify(metadata, null, 2));
        }

        return fullPath;
    }

    getAuditDirectory(auditId, dateObj) {
        // Find existing directory based on date. For simplicity, assume date is known or we can glob it.
        const year = dateObj.getFullYear().toString();
        const month = (dateObj.getMonth() + 1).toString().padStart(2, '0');
        const day = dateObj.getDate().toString().padStart(2, '0');
        const auditDirName = `audit-${auditId.toString().padStart(6, '0')}`;
        return path.join(this.baseDir, year, month, day, auditDirName);
    }

    isEvidenceComplete(auditId, dateObj) {
        const auditDir = this.getAuditDirectory(auditId, dateObj);
        if (!fs.existsSync(auditDir)) return false;

        const requiredFiles = [
            'metadata.json',
            'manifest.json',
            'executive-report.html',
            'technical-report.html',
            'security-report.zip'
        ];

        for (const file of requiredFiles) {
            if (!fs.existsSync(path.join(auditDir, file))) {
                return false;
            }
        }
        return true;
    }
}

module.exports = StorageManager;
