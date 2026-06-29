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
               cj.progress, i.rfc as instance_name
        FROM security_audits sa
        LEFT JOIN compliance_jobs cj ON sa.id = cj.audit_id
        LEFT JOIN instances i ON sa.target_id = i.id
        ORDER BY sa.date DESC
    `, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        res.json({ success: true, data: rows });
    });
});

router.get('/api/instances', (req, res) => {
    db.all(`SELECT id, rfc, company, port, install_path, status FROM instances`, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        res.json({ success: true, data: rows });
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
