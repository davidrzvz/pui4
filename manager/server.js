require('dotenv').config();
const express = require('express');
const session = require('express-session');
const bodyParser = require('body-parser');
const path = require('path');
const { exec, execFile, spawn } = require('child_process');
const fs = require('fs');
const db = require('./database');

const app = express();
const PORT = 8080;

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(express.static(path.join(__dirname, 'public')));
app.use(bodyParser.urlencoded({ extended: true }));

app.use(session({
    secret: process.env.SESSION_SECRET || 'pui-manager-secret-key-2026',
    resave: false,
    saveUninitialized: false,
    cookie: { secure: false, maxAge: 24 * 60 * 60 * 1000 }
}));

const requireAuth = (req, res, next) => {
    if (req.session.authenticated) {
        next();
    } else {
        res.redirect('/login');
    }
};

app.use((req, res, next) => {
    res.locals.user = req.session.authenticated ? process.env.MANAGER_USER : null;
    res.locals.error = req.session.error;
    res.locals.success = req.session.success;
    delete req.session.error;
    delete req.session.success;
    next();
});

// Auth Routes
app.get('/login', (req, res) => {
    if (req.session.authenticated) return res.redirect('/');
    res.render('login', { title: 'Login' });
});

app.post('/login', (req, res) => {
    const { username, password } = req.body;
    if (username === process.env.MANAGER_USER && password === process.env.MANAGER_PASS) {
        req.session.authenticated = true;
        res.redirect('/');
    } else {
        req.session.error = 'Credenciales incorrectas';
        res.redirect('/login');
    }
});

app.get('/logout', (req, res) => {
    req.session.destroy();
    res.redirect('/login');
});

// Dashboard
app.get('/', requireAuth, (req, res) => {
    db.all('SELECT * FROM instances ORDER BY created_at DESC', (err, rows) => {
        if (err) return res.status(500).send('Error de BD');
        
        const total = rows.length;
        const online = rows.filter(r => r.status === 'online').length;
        
        let nextPort = 8081;
        if (rows.length > 0) {
            const ports = rows.map(r => r.port);
            nextPort = Math.max(...ports) + 1;
        }

        const recentInstances = rows.slice(0, 5);

        res.render('dashboard', { 
            stats: { total, online, nextPort },
            recentInstances,
            title: 'Dashboard' 
        });
    });
});

// Instances List
app.get('/instances', requireAuth, (req, res) => {
    db.all('SELECT * FROM instances ORDER BY created_at DESC', (err, rows) => {
        if (err) return res.status(500).send('Error de BD');
        res.render('list', { instances: rows, title: 'Instancias' });
    });
});

// Create Instance Form
app.get('/instances/create', requireAuth, (req, res) => {
    db.all('SELECT port FROM instances', (err, rows) => {
        let nextPort = 8081;
        if (rows && rows.length > 0) {
            nextPort = Math.max(...rows.map(r => r.port)) + 1;
        }
        res.render('create', { nextPort, title: 'Crear Instancia' });
    });
});

// Create Instance Action
app.post('/instances/create', requireAuth, (req, res) => {
    const { rfc, company, port, baseUrl, apiUser, apiPassword } = req.body;
    
    const scriptPath = '/home/aplicaciones/pui/base/pui-platform/scripts/create-pui-instance.sh';
    const installPath = `/home/aplicaciones/pui/clientes/${rfc.toUpperCase()}`;
    
    // Check if script exists
    if (!fs.existsSync(scriptPath)) {
        req.session.error = `Error: El script ${scriptPath} no existe en el contenedor.`;
        return res.redirect('/instances/create');
    }

    // Wrap password in single quotes to prevent expansion issues, the bash script also handles it internally
    // but the node child_process exec uses a shell.
    // However, it's safer to escape quotes for the shell here.
    const safeCompany = company.replace(/"/g, '\\"');
    const safePassword = apiPassword.replace(/'/g, "'\\''");

    const execOptions = { cwd: '/home/aplicaciones/pui/base/pui-platform' };
    const args = ['--rfc', rfc, '--company', company, '--port', port.toString(), '--base-url', baseUrl, '--api-user', apiUser, '--api-password', apiPassword];

    execFile('bash', [scriptPath, ...args], execOptions, (error, stdout, stderr) => {
        if (error) {
            console.error(`Error: ${error.message}`);
            req.session.error = `La instancia no fue creada correctamente. Revise logs.\n\nMensaje:\n${error.message}\n\nSTDOUT:\n${stdout}\n\nSTDERR:\n${stderr}`;
            return res.redirect('/instances/create');
        }

        const validationArgs = [
            '-c',
            `if [ ! -f "/home/aplicaciones/pui/clientes/${rfc.toUpperCase()}/docker-compose.yml" ]; then exit 1; fi; ` +
            `if ! docker ps | grep -q "pui-${rfc.toLowerCase()}-nginx"; then exit 1; fi; ` +
            `HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://192.168.1.10:${port}/admin/login || echo "000"); ` +
            `if [ "$HTTP_CODE" != "200" ] && [ "$HTTP_CODE" != "302" ]; then exit 1; fi`
        ];

        execFile('bash', validationArgs, (valError) => {
            if (valError) {
                req.session.error = `La instancia no fue creada correctamente. Revise logs.\n\nSTDOUT:\n${stdout}\n\nSTDERR:\n${stderr}`;
                return res.redirect('/instances/create');
            }

            // Script succeeded, insert to DB
            const stmt = db.prepare('INSERT INTO instances (rfc, company, port, install_path) VALUES (?, ?, ?, ?)');
            stmt.run([rfc.toUpperCase(), company, port, installPath], (err) => {
                if (err) {
                    console.error(err);
                    req.session.error = 'Instancia creada, pero error al registrar en base de datos.';
                } else {
                    logAudit(rfc.toUpperCase(), 'create', `Éxito: Instancia creada en puerto ${port}`);
                    req.session.success = `Instancia para ${company} (${rfc}) creada y registrada exitosamente en el puerto ${port}.`;
                }
                res.redirect('/instances');
            });
            stmt.finalize();
        });
    });
});

// Middleware for RFC validation
const validateRfc = (req, res, next) => {
    const rfc = req.params.rfc;
    const regex = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;
    if (!regex.test(rfc)) {
        if (req.xhr || req.headers.accept.indexOf('json') > -1) {
            return res.status(400).json({ success: false, error: 'Formato de RFC inválido.' });
        }
        req.session.error = 'Formato de RFC inválido.';
        return res.redirect('/instances');
    }
    next();
};

const getInstanceByRfc = (rfc, res, callback) => {
    db.get('SELECT * FROM instances WHERE rfc = ?', [rfc.toUpperCase()], (err, instance) => {
        if (err || !instance) {
            return res.status(404).json({ success: false, error: 'Instancia no encontrada.' });
        }
        callback(instance);
    });
};

const logAudit = (rfc, action, message) => {
    const stmt = db.prepare('INSERT INTO manager_audit_logs (action, rfc, message) VALUES (?, ?, ?)');
    stmt.run([action, rfc, message]);
    stmt.finalize();
};

const execCommand = (file, args, options, res, successMsg, dbUpdate = null, auditAction = null, rfc = null) => {
    execFile(file, args, options, (error, stdout, stderr) => {
        if (error) {
            if (auditAction && rfc) logAudit(rfc, auditAction, `Error: ${error.message}`);
            return res.status(500).json({ success: false, error: error.message, stdout, stderr });
        }
        if (dbUpdate) {
            db.run(dbUpdate.query, dbUpdate.params);
        }
        if (auditAction && rfc) logAudit(rfc, auditAction, `Éxito: ${successMsg}`);
        res.json({ success: true, message: successMsg, stdout, stderr });
    });
};

// --- GESTIÓN ---

app.post('/instances/:rfc/restart', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        execCommand('docker', ['compose', 'restart'], { cwd: instance.install_path }, res, 
            'Instancia reiniciada correctamente.', 
            { query: 'UPDATE instances SET status = ? WHERE id = ?', params: ['online', instance.id] },
            'restart', instance.rfc
        );
    });
});

app.post('/instances/:rfc/stop', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        execCommand('docker', ['compose', 'stop'], { cwd: instance.install_path }, res, 
            'Instancia detenida.', 
            { query: 'UPDATE instances SET status = ? WHERE id = ?', params: ['offline', instance.id] },
            'stop', instance.rfc
        );
    });
});

app.post('/instances/:rfc/start', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        execCommand('docker', ['compose', 'up', '-d'], { cwd: instance.install_path }, res, 
            'Instancia iniciada.', 
            { query: 'UPDATE instances SET status = ? WHERE id = ?', params: ['online', instance.id] },
            'start', instance.rfc
        );
    });
});

app.post('/instances/:rfc/delete', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        // 1. docker compose down -v
        execFile('docker', ['compose', 'down', '-v'], { cwd: instance.install_path }, (error, stdout, stderr) => {
            if (error) {
                logAudit(instance.rfc, 'delete', `Error: ${error.message}`);
                return res.status(500).json({ success: false, error: 'Error al detener contenedores', details: error.message, stdout, stderr });
            }
            
            // 2. Delete folder
            try {
                fs.rmSync(instance.install_path, { recursive: true, force: true });
            } catch (fsErr) {
                logAudit(instance.rfc, 'delete', `Error FS: ${fsErr.message}`);
                return res.status(500).json({ success: false, error: 'Error al eliminar archivos', details: fsErr.message });
            }

            // 3. Delete from DB
            db.run('DELETE FROM instances WHERE id = ?', [instance.id], (dbErr) => {
                if (dbErr) {
                    logAudit(instance.rfc, 'delete', `Error DB`);
                    return res.status(500).json({ success: false, error: 'Error al eliminar de la base de datos' });
                }
                logAudit(instance.rfc, 'delete', `Éxito: Instancia eliminada`);
                res.json({ success: true, message: 'Instancia eliminada por completo.' });
            });
        });
    });
});

// --- RESPALDOS ---

const getBackupDir = (rfc) => {
    const backupDir = `/home/aplicaciones/pui/backups/${rfc.toUpperCase()}`;
    if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });
    return backupDir;
};
const getDateStr = () => new Date().toISOString().replace(/T/, '_').replace(/:/g, '-').split('.')[0];

app.post('/instances/:rfc/backup-db', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const backupDir = getBackupDir(instance.rfc);
        const dateStr = getDateStr();
        const filename = `${instance.rfc}_db_${dateStr}.sql.gz`;
        const filepath = `${backupDir}/${filename}`;
        
        // docker exec pui-{rfc_lower}-mysql sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --all-databases' | gzip > filepath
        const containerName = `pui-${instance.rfc.toLowerCase()}-mysql`;
        const cmd = `docker exec ${containerName} sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --all-databases' | gzip > ${filepath}`;
        
        exec(cmd, { cwd: instance.install_path }, (error, stdout, stderr) => {
            if (error) {
                logAudit(instance.rfc, 'backup', `Error: ${error.message}`);
                return res.status(500).json({ success: false, error: error.message, stdout, stderr });
            }
            db.run('UPDATE instances SET last_backup = CURRENT_TIMESTAMP WHERE id = ?', [instance.id]);
            logAudit(instance.rfc, 'backup', `Éxito: DB (${filename})`);
            res.json({ success: true, message: 'Respaldo DB completado', filename });
        });
    });
});

app.post('/instances/:rfc/backup-files', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const backupDir = getBackupDir(instance.rfc);
        const dateStr = getDateStr();
        const filename = `${instance.rfc}_files_${dateStr}.tar.gz`;
        const filepath = `${backupDir}/${filename}`;
        
        // tar --exclude=vendor --exclude=node_modules --exclude=.git -czf filepath -C installPath .
        execFile('tar', ['--exclude=vendor', '--exclude=node_modules', '--exclude=.git', '-czf', filepath, '-C', instance.install_path, '.'], (error, stdout, stderr) => {
            if (error) {
                logAudit(instance.rfc, 'backup', `Error files: ${error.message}`);
                return res.status(500).json({ success: false, error: error.message, stdout, stderr });
            }
            db.run('UPDATE instances SET last_backup = CURRENT_TIMESTAMP WHERE id = ?', [instance.id]);
            logAudit(instance.rfc, 'backup', `Éxito: Archivos (${filename})`);
            res.json({ success: true, message: 'Respaldo de archivos completado', filename });
        });
    });
});

app.post('/instances/:rfc/backup-full', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const backupDir = getBackupDir(instance.rfc);
        const dateStr = getDateStr();
        const dbFile = `${backupDir}/${instance.rfc}_db_${dateStr}.sql.gz`;
        const filesFile = `${backupDir}/${instance.rfc}_files_${dateStr}.tar.gz`;
        
        const containerName = `pui-${instance.rfc.toLowerCase()}-mysql`;
        const dbCmd = `docker exec ${containerName} sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --all-databases' | gzip > ${dbFile}`;
        
        exec(dbCmd, { cwd: instance.install_path }, (dbErr, dbStdout, dbStderr) => {
            if (dbErr) {
                logAudit(instance.rfc, 'backup', `Error full DB: ${dbErr.message}`);
                return res.status(500).json({ success: false, error: 'Error DB Backup', details: dbErr.message, stdout: dbStdout, stderr: dbStderr });
            }
            
            execFile('tar', ['--exclude=vendor', '--exclude=node_modules', '--exclude=.git', '-czf', filesFile, '-C', instance.install_path, '.'], (fileErr, fileStdout, fileStderr) => {
                if (fileErr) {
                    logAudit(instance.rfc, 'backup', `Error full Files: ${fileErr.message}`);
                    return res.status(500).json({ success: false, error: 'Error Files Backup', details: fileErr.message, stdout: fileStdout, stderr: fileStderr });
                }
                
                db.run('UPDATE instances SET last_backup = CURRENT_TIMESTAMP WHERE id = ?', [instance.id]);
                logAudit(instance.rfc, 'backup', `Éxito: Respaldo Completo`);
                res.json({ success: true, message: 'Respaldo completo completado', files: [dbFile, filesFile] });
            });
        });
    });
});

// --- MONITOREO & CONFIG ---

app.get('/instances/:rfc/logs', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const services = ['app', 'nginx', 'mysql', 'redis', 'queue', 'scheduler'];
        const logsData = {};
        let completed = 0;
        let hasError = false;

        services.forEach(service => {
            execFile('docker', ['compose', 'logs', '--tail=300', service], { cwd: instance.install_path }, (error, stdout, stderr) => {
                if (hasError) return;
                logsData[service] = stdout || stderr || 'Sin logs disponibles.';
                completed++;
                if (completed === services.length) {
                    res.json({ success: true, logs: logsData });
                }
            });
        });
    });
});

app.get('/instances/:rfc/status', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        execFile('docker', ['compose', 'ps', '--format', 'json'], { cwd: instance.install_path }, (error, stdout, stderr) => {
            if (error) return res.status(500).json({ success: false, error: error.message });
            try {
                // docker compose ps --format json puede devolver múltiples JSON objects (ndjson) o un array dependiendo de la versión
                // Parse lines as array if needed
                let services = [];
                if (stdout) {
                    services = stdout.trim().split('\\n').map(line => {
                        try { return JSON.parse(line); } catch(e) { return null; }
                    }).filter(Boolean);
                }
                res.json({ success: true, status: services });
            } catch(e) {
                res.status(500).json({ success: false, error: 'Error parseando status' });
            }
        });
    });
});

app.get('/instances/:rfc/config', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const envPath = path.join(instance.install_path, '.env');
        if (!fs.existsSync(envPath)) return res.status(404).json({ success: false, error: '.env no encontrado' });
        
        const envContent = fs.readFileSync(envPath, 'utf8');
        const lines = envContent.split('\\n');
        const safeConfig = {};
        
        lines.forEach(line => {
            line = line.trim();
            if (!line || line.startsWith('#')) return;
            const [key, ...valParts] = line.split('=');
            if (!key) return;
            const val = valParts.join('=');
            
            // Mask sensitive data
            if (/PASSWORD|SECRET|KEY|TOKEN/.test(key.toUpperCase())) {
                safeConfig[key] = '********';
            } else {
                safeConfig[key] = val;
            }
        });
        
        res.json({ success: true, config: safeConfig });
    });
});

app.get('/instances/:rfc/audits', requireAuth, validateRfc, (req, res) => {
    // Placeholder, as requested
    res.json({ success: true, audits: [{ date: new Date().toISOString(), event: 'Placeholder para auditorías', user: 'admin' }] });
});

// --- SETTINGS ---
app.get('/settings', requireAuth, (req, res) => {
    db.all('SELECT * FROM settings', (err, rows) => {
        if (err) return res.status(500).send('Error de BD');
        const settings = {};
        rows.forEach(r => settings[r.key] = r.value);
        res.render('settings', { settings, title: 'Configuración Global' });
    });
});

app.post('/settings', requireAuth, (req, res) => {
    const data = req.body;
    const stmt = db.prepare('UPDATE settings SET value = ? WHERE key = ?');
    for (const [key, value] of Object.entries(data)) {
        stmt.run([value, key]);
    }
    stmt.finalize();
    logAudit('GLOBAL', 'update_settings', 'Configuración global actualizada');
    req.session.success = 'Configuración actualizada exitosamente.';
    res.redirect('/settings');
});

// --- AUDIT ---
app.get('/audit', requireAuth, (req, res) => {
    db.all('SELECT * FROM manager_audit_logs ORDER BY created_at DESC LIMIT 500', (err, rows) => {
        if (err) return res.status(500).send('Error de BD');
        res.render('audit', { audits: rows, title: 'Auditoría del Sistema' });
    });
});

// --- BACKUPS ---
app.get('/backups', requireAuth, (req, res) => {
    // Read from DB setting or default
    db.get("SELECT value FROM settings WHERE key = 'BACKUP_PATH'", (err, row) => {
        const basePath = row ? row.value : '/home/aplicaciones/pui/backups';
        if (!fs.existsSync(basePath)) fs.mkdirSync(basePath, { recursive: true });

        const backupsList = [];
        const rfcs = fs.readdirSync(basePath);
        
        rfcs.forEach(rfcFolder => {
            const folderPath = path.join(basePath, rfcFolder);
            if (fs.statSync(folderPath).isDirectory()) {
                const files = fs.readdirSync(folderPath);
                files.forEach(file => {
                    const stats = fs.statSync(path.join(folderPath, file));
                    let type = 'Desconocido';
                    if (file.includes('_db_')) type = 'Base de Datos';
                    else if (file.includes('_files_')) type = 'Archivos';
                    
                    backupsList.push({
                        rfc: rfcFolder,
                        filename: file,
                        type,
                        size: (stats.size / (1024 * 1024)).toFixed(2) + ' MB',
                        date: stats.mtime
                    });
                });
            }
        });

        // Sort by date DESC
        backupsList.sort((a, b) => b.date - a.date);
        
        res.render('backups', { backups: backupsList, title: 'Respaldos Globales' });
    });
});

const validateBackupFile = (filename) => {
    // Strict validation: Only allow letters, numbers, underscores, dashes, and standard tar.gz/sql.gz extensions
    const regex = /^[A-Z0-9_\\-]+\\.(tar\\.gz|sql\\.gz|zip)$/i;
    return regex.test(filename) && !filename.includes('..');
};

app.get('/backups/download/:rfc/:file', requireAuth, validateRfc, (req, res) => {
    const { rfc, file } = req.params;
    if (!validateBackupFile(file)) {
        logAudit(rfc, 'download_backup', `Intento de descarga inválido: ${file}`);
        return res.status(400).send('Archivo inválido');
    }
    db.get("SELECT value FROM settings WHERE key = 'BACKUP_PATH'", (err, row) => {
        const basePath = row ? row.value : '/home/aplicaciones/pui/backups';
        const filepath = path.join(basePath, rfc.toUpperCase(), file);
        if (fs.existsSync(filepath)) {
            logAudit(rfc.toUpperCase(), 'download_backup', `Éxito: Descarga ${file}`);
            res.download(filepath);
        } else {
            res.status(404).send('Archivo no encontrado');
        }
    });
});

app.delete('/backups/:rfc/:file', requireAuth, validateRfc, (req, res) => {
    const { rfc, file } = req.params;
    if (!validateBackupFile(file)) {
        return res.status(400).json({ success: false, error: 'Archivo inválido' });
    }
    db.get("SELECT value FROM settings WHERE key = 'BACKUP_PATH'", (err, row) => {
        const basePath = row ? row.value : '/home/aplicaciones/pui/backups';
        const filepath = path.join(basePath, rfc.toUpperCase(), file);
        if (fs.existsSync(filepath)) {
            try {
                fs.unlinkSync(filepath);
                logAudit(rfc.toUpperCase(), 'delete_backup', `Éxito: Eliminado ${file}`);
                res.json({ success: true });
            } catch (e) {
                logAudit(rfc.toUpperCase(), 'delete_backup', `Error FS: ${e.message}`);
                res.status(500).json({ success: false, error: e.message });
            }
        } else {
            res.status(404).json({ success: false, error: 'Archivo no encontrado' });
        }
    });
});

// --- MONITORING ---
app.get('/monitoring', requireAuth, (req, res) => {
    db.all('SELECT * FROM instances ORDER BY rfc ASC', (err, rows) => {
        if (err) return res.status(500).send('Error de BD');
        res.render('monitoring', { instances: rows, title: 'Monitoreo Global' });
    });
});

app.get('/monitoring/api/:rfc', requireAuth, validateRfc, (req, res) => {
    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const containerPrefix = `pui-${instance.rfc.toLowerCase()}-`;
        
        execFile('docker', ['stats', '--no-stream', '--format', '{{.Container}}||{{.CPUPerc}}||{{.MemUsage}}||{{.Name}}'], (err, stdout) => {
            if (err) return res.status(500).json({ success: false, error: 'No disponible' });
            
            const lines = stdout.trim().split('\\n');
            const data = {
                activeContainers: 0,
                totalCpu: 0.0,
                memoryRaw: []
            };

            lines.forEach(line => {
                if (line.includes(containerPrefix)) {
                    data.activeContainers++;
                    const [id, cpu, mem, name] = line.split('||');
                    data.totalCpu += parseFloat(cpu.replace('%', '')) || 0;
                    data.memoryRaw.push(mem);
                }
            });

            res.json({ success: true, data });
        });
    });
});

// --- API CREDENTIALS ---
app.post('/instances/:rfc/change-api-password', requireAuth, validateRfc, (req, res) => {
    const { newPassword } = req.body;
    if (!newPassword || newPassword.length < 6) {
        return res.status(400).json({ success: false, error: 'La contraseña debe tener al menos 6 caracteres' });
    }

    getInstanceByRfc(req.params.rfc, res, (instance) => {
        const envPath = path.join(instance.install_path, '.env');
        if (!fs.existsSync(envPath)) return res.status(404).json({ success: false, error: '.env no encontrado' });
        
        try {
            let envContent = fs.readFileSync(envPath, 'utf8');
            // Safe replace without bash sed
            // API_PASSWORD could be plain or wrapped in quotes
            const regex = /^API_PASSWORD=.*$/m;
            if (regex.test(envContent)) {
                envContent = envContent.replace(regex, `API_PASSWORD="${newPassword.replace(/"/g, '\\"')}"`);
            } else {
                envContent += `\nAPI_PASSWORD="${newPassword.replace(/"/g, '\\"')}"`;
            }
            fs.writeFileSync(envPath, envContent);
            
            // Now clear cache and restart
            execFile('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'optimize:clear'], { cwd: instance.install_path }, (cacheErr) => {
                if (cacheErr) {
                    logAudit(instance.rfc, 'change_api_password', `Error caché: ${cacheErr.message}`);
                    return res.status(500).json({ success: false, error: 'Error limpiando caché de Laravel', details: cacheErr.message });
                }
                
                execFile('docker', ['compose', 'restart', 'app', 'queue', 'scheduler'], { cwd: instance.install_path }, (restErr) => {
                    if (restErr) {
                        logAudit(instance.rfc, 'change_api_password', `Error reinicio: ${restErr.message}`);
                        return res.status(500).json({ success: false, error: 'Error reiniciando servicios', details: restErr.message });
                    }
                    
                    logAudit(instance.rfc, 'change_api_password', `Éxito: Contraseña API modificada`);
                    res.json({ success: true, message: 'Contraseña API actualizada exitosamente' });
                });
            });

        } catch (e) {
            logAudit(instance.rfc, 'change_api_password', `Error FS: ${e.message}`);
            res.status(500).json({ success: false, error: 'Error al escribir .env', details: e.message });
        }
    });
});

app.listen(PORT, '0.0.0.0', () => {
    console.log(`PUI Manager corriendo en el puerto ${PORT}`);
});
