const db = require('../../database');
const TargetProvider = require('./TargetProvider');

class AuditEngine {
    constructor(scannerManager, storageManager) {
        this.scannerManager = scannerManager;
        this.storageManager = storageManager;
    }

    /**
     * Start a Code Audit (SAST/SCA).
     */
    async runCodeAudit(targetId, codeHash, commit, branch, laravelVersion, phpVersion, user) {
        // 1. Check if SHA256 already exists
        const existingVersion = await this._getCodeVersionByHash(codeHash);
        
        if (existingVersion) {
            // Find latest audit for this code version
            const latestAudit = await this._getLatestAuditForCodeVersion(existingVersion.id);
            if (latestAudit) {
                // Verify physical evidence
                const isComplete = this.storageManager.isEvidenceComplete(latestAudit.id, new Date(latestAudit.date));
                if (isComplete) {
                    return {
                        reusable: true,
                        code_version_id: existingVersion.id,
                        auditId: latestAudit.id,
                        message: 'This code version has already been certified.'
                    };
                } else {
                    // Recreate audit since evidence is missing
                    const newAuditId = await this._createAuditRecord(targetId, existingVersion.id, 'Code Audit');
                    return {
                        reusable: false,
                        auditId: newAuditId,
                        message: 'Code version already exists, but evidence is missing. Evidence regeneration has been queued.'
                    };
                }
            } else {
                // Code version exists but no audit record found, create one
                const newAuditId = await this._createAuditRecord(targetId, existingVersion.id, 'Code Audit');
                return {
                    reusable: false,
                    auditId: newAuditId,
                    message: 'Code version exists, creating new audit.'
                };
            }
        }

        // 2. Create new code version entry
        const codeVersionId = await this._createCodeVersion(codeHash, commit, branch, laravelVersion, phpVersion, user);

        // 3. Create Audit record
        const auditId = await this._createAuditRecord(targetId, codeVersionId, 'Code Audit');

        // 4. Return auditId to be queued
        return {
            reusable: false,
            auditId: auditId,
            message: 'New Code Audit queued.'
        };
    }

    /**
     * Start an Instance Audit (DAST).
     */
    async runInstanceAudit(targetId, profile) {
        const target = await TargetProvider.getTarget(targetId);
        if (!target) throw new Error('Target not found');

        const auditId = await this._createAuditRecord(targetId, null, profile || 'Instance Audit');
        
        return {
            auditId: auditId,
            message: 'Instance Audit queued.'
        };
    }

    // Helper DB methods
    _getCodeVersionByHash(sha256) {
        return new Promise((resolve, reject) => {
            db.get('SELECT * FROM security_code_versions WHERE sha256 = ?', [sha256], (err, row) => {
                if (err) reject(err);
                else resolve(row);
            });
        });
    }

    _getLatestAuditForCodeVersion(codeVersionId) {
        return new Promise((resolve, reject) => {
            db.get('SELECT * FROM security_audits WHERE code_version_id = ? ORDER BY id DESC LIMIT 1', [codeVersionId], (err, row) => {
                if (err) reject(err);
                else resolve(row);
            });
        });
    }

    _createCodeVersion(sha256, commit_hash, branch, laravel_version, php_version, user) {
        return new Promise((resolve, reject) => {
            db.run(
                `INSERT INTO security_code_versions (sha256, commit_hash, branch, laravel_version, php_version, created_by) VALUES (?, ?, ?, ?, ?, ?)`,
                [sha256, commit_hash, branch, laravel_version, php_version, user],
                function(err) {
                    if (err) reject(err);
                    else resolve(this.lastID);
                }
            );
        });
    }

    _createAuditRecord(targetId, codeVersionId, profile) {
        return new Promise((resolve, reject) => {
            db.run(
                `INSERT INTO security_audits (target_id, code_version_id, profile) VALUES (?, ?, ?)`,
                [targetId, codeVersionId, profile],
                function(err) {
                    if (err) reject(err);
                    else resolve(this.lastID);
                }
            );
        });
    }
}

module.exports = AuditEngine;
