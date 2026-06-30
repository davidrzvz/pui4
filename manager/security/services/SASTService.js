const { execFile } = require('child_process');
const util = require('util');
const execFilePromise = util.promisify(execFile);
const fs = require('fs');

class SASTService {
    async execute(instance) {
        const startTime = Date.now();
        let findings = [];
        let status = 'Completado';
        
        try {
            const installPath = instance.install_path;

            if (!fs.existsSync(installPath)) {
                throw new Error(`La ruta del proyecto no existe: ${installPath}`);
            }

            const { stdout } = await execFilePromise('docker', [
                'run', '--rm', '-v', `${installPath}:/src`, 
                'returntocorp/semgrep', 'semgrep', 'scan', '--json', '/src'
            ], { maxBuffer: 10 * 1024 * 1024 });

            const result = JSON.parse(stdout);
            
            if (result.results && result.results.length > 0) {
                findings = result.results.map(f => ({
                    title: f.check_id,
                    severity: f.extra?.severity || 'Medium',
                    description: f.extra?.message || 'Vulnerabilidad detectada en código.',
                    recommendation: 'Revisar la línea de código y aplicar mitigación acorde al CWE.'
                }));
            }
        } catch (error) {
            console.error('SAST Error:', error);
            status = 'Fallido';
            findings.push({
                title: 'Error de Ejecución o Validación',
                severity: 'High',
                description: `No se pudo ejecutar SAST: ${error.message}`,
                recommendation: 'Verificar la ruta del proyecto y conectividad Docker.'
            });
        }
        
        const duration = Date.now() - startTime;
        return {
            target: `${instance.company} (${instance.rfc})`,
            type: 'SAST',
            tool: 'Semgrep (Docker)',
            config: 'Default Ruleset',
            status: status,
            date: new Date().toLocaleString(),
            duration: `${duration} ms`,
            findings: findings
        };
    }
}

module.exports = new SASTService();
