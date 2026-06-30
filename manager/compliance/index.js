const express = require('express');
const router = express.Router();
const toolRegistry = require('./engine/ToolRegistry');
const SQLiteQueue = require('./jobs/SQLiteQueue');
const Worker = require('./jobs/Worker');
const JobDispatcher = require('./jobs/JobDispatcher');
const AuditEngine = require('./engine/AuditEngine');
const ScannerManager = require('./scanners/ScannerManager');
const StorageManager = require('./storage/StorageManager');
const db = require('../database');

// Initialize Queue, Worker, Engine and Dispatcher
const queue = new SQLiteQueue();
const worker = new Worker(queue);
const storageManager = new StorageManager();
const scannerManager = new ScannerManager();
const auditEngine = new AuditEngine(scannerManager, storageManager);
const jobDispatcher = new JobDispatcher(queue);

worker.start(); // Starts the background process

// Ensure tool registry is up to date when accessing the module
router.use(async (req, res, next) => {
    await toolRegistry.checkTools();
    next();
});

router.use(express.json());
router.use(express.urlencoded({ extended: true }));

// Dashboard routes
router.get('/dashboard', (req, res) => {
    res.render('compliance/dashboard', { activeMenu: 'compliance' });
});

router.get('/auditorias', (req, res) => {
    res.render('compliance/auditorias', { activeMenu: 'compliance' });
});

router.post('/api/auditorias/code', async (req, res) => {
    try {
        const { source_path, target_id } = req.body;
        // Basic placeholder logic to integrate with auditEngine
        const result = await auditEngine.runCodeAudit(target_id, 'PENDING_HASH', 'HEAD', 'main', 'N/A', 'N/A', 'System');
        
        if (!result.reusable && result.auditId) {
            await jobDispatcher.dispatch({ auditId: result.auditId });
        }
        
        res.json({ success: true, data: result });
    } catch (error) {
        console.error(error);
        res.status(500).json({ success: false, error: error.message });
    }
});

router.post('/api/auditorias/instance', async (req, res) => {
    try {
        const { target_id, profile } = req.body;
        const result = await auditEngine.runInstanceAudit(target_id, profile);
        await jobDispatcher.dispatch({ auditId: result.auditId });
        res.json({ success: true, data: result });
    } catch (error) {
        console.error(error);
        res.status(500).json({ success: false, error: error.message });
    }
});

router.get('/api/audits/history', (req, res) => {
    db.all(`
        SELECT sa.id, sa.status, sa.date, sa.profile, sa.vulnerabilities_count,
               (SELECT progress FROM compliance_jobs WHERE audit_id = sa.id ORDER BY id DESC LIMIT 1) as progress,
               i.rfc as instance_name
        FROM security_audits sa
        LEFT JOIN instances i ON sa.target_id = i.id
        ORDER BY sa.date DESC
    `, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        
        // Add evidence_complete flag
        const results = rows.map(row => {
            let evidenceComplete = false;
            if (row.status === 'Finished' || row.status === 'finished' || row.status === 'Failed' || row.status === 'failed') {
                evidenceComplete = storageManager.isEvidenceComplete(row.id, new Date(row.date));
            }
            return { ...row, evidence_complete: evidenceComplete };
        });
        
        res.json({ success: true, data: results });
    });
});

router.post('/api/evidences/:id/regenerate', async (req, res) => {
    const auditId = req.params.id;
    try {
        db.get(`SELECT * FROM security_audits WHERE id = ?`, [auditId], async (err, audit) => {
            if (err || !audit) return res.status(404).json({ success: false, error: "Audit not found" });

            const ReportGenerator = require('./reports/ReportGenerator');
            const reportGen = new ReportGenerator(storageManager);
            
            const auditData = {
                id: audit.id,
                date: audit.date,
                profile: audit.profile,
                vulnerabilities_count: audit.vulnerabilities_count || 0,
                status: audit.status || 'Finished',
                toolsUsed: [],
                toolVersions: {},
                durationMs: 0
            };
            
            const auditDir = storageManager.getAuditDirectory(audit.id, new Date(audit.date));
            const metadataPath = require('path').join(auditDir, 'metadata.json');
            const fs = require('fs');
            if (fs.existsSync(metadataPath)) {
                try {
                    const existingMeta = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
                    if (existingMeta.herramientas_utilizadas) {
                        auditData.toolsUsed = existingMeta.herramientas_utilizadas;
                    }
                    if (existingMeta.status) {
                        auditData.status = existingMeta.status;
                    }
                } catch(e) {}
            }

            try {
                await reportGen.generateReports(audit.id, auditData, new Date(audit.date));
                res.json({ success: true, message: "Evidencia regenerada exitosamente." });
            } catch (genErr) {
                res.status(500).json({ success: false, error: "Error regenerando evidencia: " + genErr.message });
            }
        });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

router.get('/api/instances', (req, res) => {
    db.all(`SELECT id, rfc, company, port, install_path, status FROM instances`, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        res.json({ success: true, data: rows });
    });
});

router.get('/api/evidences/:id/zip', (req, res) => {
    const auditId = req.params.id;
    db.get(`SELECT date FROM security_audits WHERE id = ?`, [auditId], (err, row) => {
        if (err || !row) return res.status(404).json({ success: false, error: "Evidence not generated" });
        const auditDir = storageManager.getAuditDirectory(auditId, new Date(row.date));
        
        const zipPath = require('path').join(auditDir, 'security-report.zip');
        const tarPath = require('path').join(auditDir, 'security-report.tar.gz');
        const fs = require('fs');
        
        if (fs.existsSync(zipPath) && fs.statSync(zipPath).size > 0) {
            return res.download(zipPath, `audit-${auditId}-security-report.zip`);
        }
        
        if (fs.existsSync(tarPath) && fs.statSync(tarPath).size > 0) {
            return res.download(tarPath, `audit-${auditId}-security-report.tar.gz`);
        }
        
        res.status(404).json({ success: false, error: "Evidence not generated or incomplete" });
    });
});

router.get('/api/evidences/:id/executive', (req, res) => {
    const auditId = req.params.id;
    db.get(`SELECT date FROM security_audits WHERE id = ?`, [auditId], (err, row) => {
        if (err || !row) return res.status(404).json({ success: false, error: "Evidence not generated" });
        const auditDir = storageManager.getAuditDirectory(auditId, new Date(row.date));
        const pdfPath = require('path').join(auditDir, 'executive-report.pdf');
        const htmlPath = require('path').join(auditDir, 'executive-report.html');
        if (require('fs').existsSync(pdfPath)) {
            res.sendFile(pdfPath);
        } else if (require('fs').existsSync(htmlPath)) {
            res.sendFile(htmlPath);
        } else {
            res.status(404).json({ success: false, error: "Evidence not generated" });
        }
    });
});

router.get('/api/evidences/:id/technical', (req, res) => {
    const auditId = req.params.id;
    db.get(`SELECT date FROM security_audits WHERE id = ?`, [auditId], (err, row) => {
        if (err || !row) return res.status(404).json({ success: false, error: "Evidence not generated" });
        const auditDir = storageManager.getAuditDirectory(auditId, new Date(row.date));
        const pdfPath = require('path').join(auditDir, 'technical-report.pdf');
        const htmlPath = require('path').join(auditDir, 'technical-report.html');
        if (require('fs').existsSync(pdfPath)) {
            res.sendFile(pdfPath);
        } else if (require('fs').existsSync(htmlPath)) {
            res.sendFile(htmlPath);
        } else {
            res.status(404).json({ success: false, error: "Evidence not generated" });
        }
    });
});

router.get('/evidencias', (req, res) => {
    res.render('compliance/evidencias', { activeMenu: 'compliance' });
});

router.get('/herramientas', (req, res) => {
    const tools = toolRegistry.getToolStatus();
    res.render('compliance/herramientas', { tools, activeMenu: 'compliance' });
});

router.get('/configuracion', (req, res) => {
    res.render('compliance/configuracion', { activeMenu: 'compliance' });
});

module.exports = router;
