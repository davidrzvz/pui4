const { execFile } = require('child_process');
const util = require('util');
const execFilePromise = util.promisify(execFile);
const fs = require('fs');
const path = require('path');

class SCAService {
    async execute(instance) {
        const startTime = Date.now();
        let findings = [];
        let status = 'Completado';
        let toolsUsed = [];

        try {
            const installPath = instance.install_path;

            if (!fs.existsSync(installPath)) {
                throw new Error(`La ruta del proyecto no existe: ${installPath}`);
            }

            const composePath = path.join(installPath, 'docker-compose.yml');
            if (!fs.existsSync(composePath)) {
                throw new Error(`El archivo docker-compose correspondiente no existe en: ${installPath}`);
            }

            const hasComposer = fs.existsSync(path.join(installPath, 'composer.json'));
            const hasNpm = fs.existsSync(path.join(installPath, 'package.json'));

            if (!hasComposer && !hasNpm) {
                status = 'Sin dependencias';
                findings.push({
                    title: 'Sin gestores de dependencias',
                    severity: 'Low',
                    description: 'No se encontró composer.json ni package.json en la ruta de instalación.',
                    recommendation: 'Ninguna acción requerida.'
                });
                return this.buildResult(status, toolsUsed, findings, startTime, instance);
            }

            // Descubrir contenedores corriendo dinámicamente
            const { stdout } = await execFilePromise('docker', ['compose', 'ps', '--format', 'json'], { cwd: installPath });
            
            let services = [];
            if (stdout) {
                services = stdout.trim().split('\n').map(line => {
                    try { return JSON.parse(line); } catch(e) { return null; }
                }).filter(Boolean);
            }

            if (services.length === 0) {
                throw new Error('No se encontraron contenedores corriendo para esta instancia.');
            }

            let phpContainer = null;
            let npmContainer = null;

            for (const srv of services) {
                const srvName = srv.Service;
                // Probar PHP
                if (!phpContainer) {
                    try {
                        await execFilePromise('docker', ['compose', 'exec', '-T', srvName, 'php', '-v'], { cwd: installPath });
                        phpContainer = srvName;
                    } catch (e) {}
                }
                // Probar NPM
                if (!npmContainer) {
                    try {
                        await execFilePromise('docker', ['compose', 'exec', '-T', srvName, 'npm', '-v'], { cwd: installPath });
                        npmContainer = srvName;
                    } catch (e) {}
                }
            }

            if (hasComposer) {
                if (!phpContainer) {
                    status = 'Parcial';
                    findings.push({
                        title: 'Error de Validación',
                        severity: 'High',
                        description: 'No se encontró un contenedor con PHP para ejecutar composer audit.',
                        recommendation: 'Asegurar que la instancia tiene un contenedor PHP corriendo.'
                    });
                } else {
                    toolsUsed.push('Composer Audit');
                    try {
                        await execFilePromise('docker', ['compose', 'exec', '-T', phpContainer, 'composer', 'audit', '--format=json'], { cwd: installPath });
                    } catch (composerErr) {
                        if (composerErr.stdout) {
                            try {
                                const result = JSON.parse(composerErr.stdout);
                                if (result.vulnerabilities) {
                                    for (const [pkg, vulns] of Object.entries(result.vulnerabilities)) {
                                        vulns.forEach(v => {
                                            findings.push({
                                                title: `Composer: ${pkg}`,
                                                severity: 'High',
                                                description: v.title || v.advisoryId || 'Vulnerabilidad en paquete PHP',
                                                recommendation: 'Actualizar paquete usando composer update.'
                                            });
                                        });
                                    }
                                }
                            } catch (e) {
                                console.error('Failed to parse composer audit json');
                            }
                        } else {
                            throw composerErr;
                        }
                    }
                }
            }

            if (hasNpm) {
                if (!npmContainer) {
                    status = 'Parcial';
                    findings.push({
                        title: 'Error de Validación',
                        severity: 'High',
                        description: 'No se encontró un contenedor con NPM para ejecutar npm audit.',
                        recommendation: 'Asegurar que la instancia tiene un contenedor con Node.js corriendo.'
                    });
                } else {
                    toolsUsed.push('npm audit');
                    try {
                        await execFilePromise('docker', ['compose', 'exec', '-T', npmContainer, 'npm', 'audit', '--json'], { cwd: installPath });
                    } catch (npmErr) {
                        if (npmErr.stdout) {
                            try {
                                const result = JSON.parse(npmErr.stdout);
                                if (result.vulnerabilities) {
                                    for (const [pkg, vuln] of Object.entries(result.vulnerabilities)) {
                                        findings.push({
                                            title: `NPM: ${pkg}`,
                                            severity: vuln.severity || 'Medium',
                                            description: vuln.name || 'Vulnerabilidad en paquete Node',
                                            recommendation: `Actualizar paquete ${pkg}.`
                                        });
                                    }
                                }
                            } catch (e) {
                                console.error('Failed to parse npm audit json');
                            }
                        } else {
                            throw npmErr;
                        }
                    }
                }
            }

        } catch (error) {
            console.error('SCA Error:', error);
            status = 'Fallido';
            findings.push({
                title: 'Error de Ejecución o Validación',
                severity: 'High',
                description: `No se pudo ejecutar SCA: ${error.message}`,
                recommendation: 'Verificar la ruta del proyecto y los contenedores de la instancia.'
            });
        }
        
        return this.buildResult(status, toolsUsed, findings, startTime, instance);
    }

    buildResult(status, toolsUsed, findings, startTime, instance) {
        const duration = Date.now() - startTime;
        return {
            target: `${instance.company} (${instance.rfc})`,
            type: 'SCA',
            tool: toolsUsed.join(' + ') || 'SCA',
            config: 'Default Scan',
            status: status,
            date: new Date().toLocaleString(),
            duration: `${duration} ms`,
            findings: findings
        };
    }
}

module.exports = new SCAService();
