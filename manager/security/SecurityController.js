const express = require('express');
const router = express.Router();
const db = require('../database');
const path = require('path');
const fs = require('fs');

const sastService = require('./services/SASTService');
const scaService = require('./services/SCAService');
const dastService = require('./services/DASTService');
const PdfReportGenerator = require('./reports/PdfReportGenerator');

const reportGenerator = new PdfReportGenerator();

router.use(express.json());
router.use(express.urlencoded({ extended: true }));

// Frontend Views
router.get('/', (req, res) => {
    db.all('SELECT * FROM instances ORDER BY created_at DESC', (err, instances) => {
        if (err) return res.status(500).send('Error de BD');
        res.render('security/index', { instances, activeMenu: 'security' });
    });
});

// API Endpoints
router.post('/api/execute/:type/:instanceId', (req, res) => {
    const type = req.params.type.toUpperCase();
    const instanceId = req.params.instanceId;
    let service;
    
    if (type === 'SAST') service = sastService;
    else if (type === 'SCA') service = scaService;
    else if (type === 'DAST') service = dastService;
    else if (type !== 'ALL') return res.status(400).json({ success: false, error: 'Invalid type' });

    db.get('SELECT * FROM instances WHERE id = ?', [instanceId], async (err, instance) => {
        if (err || !instance) return res.status(404).json({ success: false, error: 'Instancia no encontrada' });

        try {
            let typesToRun = type === 'ALL' ? ['SAST', 'SCA', 'DAST'] : [type];
            let results = [];
            
            for (let t of typesToRun) {
                let currentService;
                if (t === 'SAST') currentService = sastService;
                if (t === 'SCA') currentService = scaService;
                if (t === 'DAST') currentService = dastService;

                // 1. Ejecutar la herramienta (síncrono, con await)
                const rawResult = await currentService.execute(instance);

                // 2. Generar reportes PDF y HTML
                const finalResult = await reportGenerator.generate(t, rawResult);

                // 3. Guardar en base de datos (Historial)
                const reportId = await new Promise((resolve, reject) => {
                    db.run(`INSERT INTO security_reports (type, tool, duration, status, findings, json_path, html_path, pdf_path) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)`, 
                        [
                            finalResult.type,
                            finalResult.tool,
                            finalResult.duration,
                            finalResult.status,
                            finalResult.findings.length,
                            finalResult.json_path,
                            finalResult.html_path,
                            finalResult.pdf_path
                        ],
                        function(dbErr) {
                            if (dbErr) reject(dbErr);
                            else resolve(this.lastID);
                        }
                    );
                });
                
                finalResult.reportId = reportId;
                results.push(finalResult);
            }

            res.json({ success: true, data: type === 'ALL' ? results : results[0], reportId: results[0].reportId });
        } catch (execErr) {
            console.error(`[Error] Failed executing ${type}:`, execErr);
            res.status(500).json({ success: false, error: execErr.message });
        }
    });
});



router.get('/api/history', (req, res) => {
    db.all(`SELECT * FROM security_reports ORDER BY date DESC`, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        res.json({ success: true, data: rows });
    });
});

router.get('/api/download/pdf/:id', (req, res) => {
    const id = req.params.id;
    db.get(`SELECT pdf_path FROM security_reports WHERE id = ?`, [id], (err, row) => {
        if (err || !row || !row.pdf_path) return res.status(404).send("PDF no encontrado");
        if (fs.existsSync(row.pdf_path)) {
            res.download(row.pdf_path);
        } else {
            res.status(404).send("El archivo PDF no existe físicamente en el disco.");
        }
    });
});

router.get('/api/download/html/:id', (req, res) => {
    const id = req.params.id;
    db.get(`SELECT html_path FROM security_reports WHERE id = ?`, [id], (err, row) => {
        if (err || !row || !row.html_path) return res.status(404).send("HTML no encontrado");
        if (fs.existsSync(row.html_path)) {
            res.download(row.html_path);
        } else {
            res.status(404).send("El archivo HTML no existe físicamente en el disco.");
        }
    });
});

module.exports = router;
