const { execFile } = require('child_process');
const util = require('util');
const execFilePromise = util.promisify(execFile);
const fs = require('fs');
const path = require('path');
const db = require('../../database');

class DASTService {
    async execute(instance) {
        const startTime = Date.now();
        let findings = [];
        let status = 'Completado';
        
        try {
            // Resolver URL dinámicamente usando la BD
            const serverIp = await new Promise((resolve, reject) => {
                db.get("SELECT value FROM settings WHERE key = 'SERVER_IP'", (err, row) => {
                    if (err) return reject(err);
                    resolve(row ? row.value : '127.0.0.1');
                });
            });

            const targetUrl = `http://${serverIp}:${instance.port}`;

            // Validar si la URL responde
            try {
                const { stdout } = await execFilePromise('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', targetUrl]);
                const httpCode = stdout.trim();
                if (httpCode === '000') {
                    throw new Error(`La URL ${targetUrl} no responde.`);
                }
            } catch (err) {
                throw new Error(`La URL de acceso ${targetUrl} no está disponible.`);
            }

            const tempDir = path.join(process.cwd(), 'storage', 'temp');
            if (!fs.existsSync(tempDir)) fs.mkdirSync(tempDir, { recursive: true });
            
            try {
                await execFilePromise('docker', [
                    'run', '--rm', '--network', 'host',
                    '-v', `${tempDir}:/zap/wrk/:rw`,
                    'ghcr.io/zaproxy/zaproxy:stable',
                    'zap-baseline.py', '-t', targetUrl, '-J', 'report.json'
                ]);
            } catch (zapErr) {
                // ZAP arroja error si encuentra vulnerabilidades o warnings. Continuamos.
            }

            const reportPath = path.join(tempDir, 'report.json');
            if (fs.existsSync(reportPath)) {
                const result = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
                if (result.site && result.site.length > 0) {
                    const alerts = result.site[0].alerts || [];
                    alerts.forEach(alert => {
                        findings.push({
                            title: alert.name,
                            severity: alert.riskdesc || 'Medium',
                            description: alert.desc || 'Vulnerabilidad detectada',
                            recommendation: alert.solution || 'Revisar documentación de OWASP.'
                        });
                    });
                }
                // Limpiar archivo temporal
                fs.unlinkSync(reportPath);
            } else {
                throw new Error("El reporte JSON de ZAP no fue generado.");
            }

        } catch (error) {
            console.error('DAST Error:', error);
            status = 'Fallido';
            findings.push({
                title: 'Error de Ejecución o Validación',
                severity: 'High',
                description: `No se pudo ejecutar DAST: ${error.message}`,
                recommendation: 'Verificar conectividad a la URL de la instancia o configuración.'
            });
        }
        
        const duration = Date.now() - startTime;
        return {
            target: `${instance.company} (${instance.rfc})`,
            type: 'DAST',
            tool: 'OWASP ZAP (Docker)',
            config: 'Baseline Scan',
            status: status,
            date: new Date().toLocaleString(),
            duration: `${duration} ms`,
            findings: findings
        };
    }
}

module.exports = new DASTService();
