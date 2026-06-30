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

            const cmdArgs = [
                'run', '--rm', '-v', `${installPath}:/src:ro`, 
                'returntocorp/semgrep', 'semgrep', 'scan', 
                '--config=p/php', '--json', '--metrics=off',
                '--exclude', 'vendor',
                '--exclude', 'node_modules',
                '--exclude', 'storage',
                '--exclude', 'bootstrap/cache',
                '/src'
            ];

            const { stdout } = await execFilePromise('docker', cmdArgs, { maxBuffer: 10 * 1024 * 1024, timeout: 2 * 60 * 1000 });

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
            
            let errMsg = error.message;
            if (error.stderr) {
                errMsg += '\n' + error.stderr;
            } else if (error.stdout) {
                errMsg += '\n' + error.stdout;
            }

            findings.push({
                title: 'Error de Ejecución o Validación',
                severity: 'High',
                description: `No se pudo ejecutar SAST:\n${errMsg}`,
                recommendation: 'Verificar la herramienta y los logs para resolver el problema.'
            });
        }
        
        const duration = Date.now() - startTime;
        return {
            target: `${instance.company} (${instance.rfc})`,
            targetPath: instance.install_path,
            command: 'docker run --rm returntocorp/semgrep semgrep scan ...',
            type: 'SAST',
            tool: 'Semgrep (Docker)',
            config: 'p/php',
            status: status,
            date: new Date().toLocaleString(),
            duration: `${duration} ms`,
            findings: findings
        };
    }
}

module.exports = new SASTService();
