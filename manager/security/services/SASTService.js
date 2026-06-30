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

            const rulePaths = [
                path.join(installPath, 'semgrep.yml'),
                path.join(installPath, '.semgrep.yml'),
                path.join(installPath, '.semgrep')
            ];
            
            const hasLocalRules = rulePaths.some(p => fs.existsSync(p));

            if (!hasLocalRules) {
                throw new Error("No hay reglas SAST locales configuradas");
            }

            const cmdArgs = [
                'run', '--rm', '-v', `${installPath}:/src:ro`, 
                'returntocorp/semgrep', 'semgrep', 'scan', 
                '--json', '--metrics=off',
                '--exclude', 'vendor',
                '--exclude', 'node_modules',
                '--exclude', 'storage',
                '--exclude', 'bootstrap/cache',
                '/src'
            ];

            console.log(`[SASTService] Comando: docker ${cmdArgs.join(' ')}`);

            const { stdout, stderr } = await new Promise((resolve, reject) => {
                const child = require('child_process').spawn('docker', cmdArgs);
                
                let out = '';
                let errStr = '';
                let isDone = false;
                
                child.stdout.on('data', data => { out += data.toString(); });
                child.stderr.on('data', data => { errStr += data.toString(); });
                
                const timer = setTimeout(() => {
                    if (!isDone) {
                        isDone = true;
                        child.kill('SIGKILL');
                        reject(new Error(`Timeout de 120s excedido.\nStderr: ${errStr}`));
                    }
                }, 120000);

                child.on('error', (err) => {
                    if (!isDone) {
                        isDone = true;
                        clearTimeout(timer);
                        reject(new Error(`Error al iniciar docker: ${err.message}`));
                    }
                });

                child.on('close', (code) => {
                    if (!isDone) {
                        isDone = true;
                        clearTimeout(timer);
                        // code 0 means no findings, code 1 means findings
                        if (code !== 0 && code !== 1) {
                            reject(new Error(`Semgrep falló con código ${code}.\nStderr: ${errStr}`));
                        } else {
                            resolve({ stdout: out, stderr: errStr });
                        }
                    }
                });
            });

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
                description: `No se pudo ejecutar SAST:\n${error.message}`,
                recommendation: 'Verificar la herramienta, proveer reglas locales, o revisar los logs para resolver el problema.'
            });
        }
        
        const duration = Date.now() - startTime;
        return {
            target: `${instance.company} (${instance.rfc})`,
            targetPath: instance.install_path,
            command: 'docker run --rm returntocorp/semgrep semgrep scan ...',
            type: 'SAST',
            tool: 'Semgrep (Docker)',
            config: 'Local Rules',
            status: status,
            date: new Date().toLocaleString(),
            duration: `${duration} ms`,
            findings: findings
        };
    }
}

module.exports = new SASTService();
