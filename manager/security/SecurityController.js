const express = require('express');
const router = express.Router();
const db = require('../database');
const path = require('path');
const fs = require('fs');
const { exec } = require('child_process');
const util = require('util');
const execPromise = util.promisify(exec);

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
    
    if (!['SAST', 'SCA', 'DAST', 'ALL'].includes(type)) {
        return res.status(400).json({ success: false, error: 'Invalid type' });
    }

    console.log(`[SecurityController] Petición recibida. Type: ${type}, InstanceID: ${instanceId}`);

    db.get('SELECT * FROM instances WHERE id = ?', [instanceId], async (err, instance) => {
        if (err || !instance) {
            console.log(`[SecurityController] Instancia no resuelta para ID: ${instanceId}`);
            return res.status(404).json({ success: false, error: 'Instancia no encontrada' });
        }
        
        console.log(`[SecurityController] Instancia resuelta: ${instance.rfc} en ${instance.install_path}`);

        try {
            const serverIp = await new Promise((resolve) => {
                db.get("SELECT value FROM settings WHERE key = 'SERVER_IP'", (err, row) => {
                    resolve(row ? row.value : '127.0.0.1');
                });
            });
            const targetUrl = `http://${serverIp}:${instance.port}`;
            const outDir = '/app/data/evidencias';
            const runnerPath = path.join(process.cwd(), 'security-runner', 'run.py');
            
            const cmd = `python3 "${runnerPath}" --name "${instance.rfc}" --code "${instance.install_path}" --url "${targetUrl}" --output "${outDir}" --type "${type}"`;
            
            console.log(`[SecurityController] Ejecutando: ${cmd}`);
            
            // Timeout de 15 minutos en total para el script de python
            const { stdout, stderr } = await execPromise(cmd, { timeout: 900000 });
            console.log(`[SecurityController] Salida del runner: \n${stdout}`);
            if (stderr) console.error(`[SecurityController] Runner stderr: \n${stderr}`);
            
            // Determinar rutas de archivos generados
            const instanceOutDir = path.join(outDir, instance.rfc);
            
            const htmlLinks = {
                SAST: `/security/api/download/html/file?path=${encodeURIComponent(path.join(instanceOutDir, 'SAST.html'))}`,
                SCA: `/security/api/download/html/file?path=${encodeURIComponent(path.join(instanceOutDir, 'SCA.html'))}`,
                DAST: `/security/api/download/html/file?path=${encodeURIComponent(path.join(instanceOutDir, 'DAST.html'))}`,
                RESUMEN: `/security/api/download/html/file?path=${encodeURIComponent(path.join(instanceOutDir, 'RESUMEN_EVIDENCIA.html'))}`
            };

            // Simular respuesta compatible con el front
            res.json({ 
                success: true, 
                message: "Ejecución finalizada.",
                stdout: stdout,
                links: htmlLinks
            });
        } catch (execErr) {
            console.error(`[Error] Failed executing python runner:`, execErr);
            res.status(500).json({ success: false, error: execErr.message || execErr.stderr || "Error ejecutando el script" });
        }
    });
});

router.get('/api/history', (req, res) => {
    // History can be mocked or adjusted if needed, but since we removed DB insertion from the controller
    // we just return empty or existing rows. 
    db.all(`SELECT * FROM security_reports ORDER BY date DESC`, [], (err, rows) => {
        if (err) return res.status(500).json({ success: false, error: err.message });
        res.json({ success: true, data: rows });
    });
});

router.get('/api/download/html/file', (req, res) => {
    const filePath = req.query.path;
    if (!filePath || !fs.existsSync(filePath)) {
        return res.status(404).send("HTML no encontrado en el disco.");
    }
    res.sendFile(filePath);
});

// Mantener por compatibilidad si la UI los usa, aunque la BD ya no se llene con PDFs
router.get('/api/download/pdf/:id', (req, res) => res.status(404).send("Generación de PDF automático desactivada. Usa el botón de impresión en el HTML."));
router.get('/api/download/html/:id', (req, res) => res.status(404).send("Usa la nueva ruta de descarga de HTML"));

module.exports = router;
