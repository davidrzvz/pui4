require('dotenv').config();
const express = require('express');
const session = require('express-session');
const bodyParser = require('body-parser');
const path = require('path');
const { exec } = require('child_process');
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

    const cmd = `bash ${scriptPath} --rfc "${rfc}" --company "${safeCompany}" --port ${port} --base-url "${baseUrl}" --api-user "${apiUser}" --api-password '${safePassword}'`;
    
    // We execute the script in the context of the base directory to be safe, though the script handles clone
    const execOptions = { cwd: '/home/aplicaciones/pui/base/pui-platform' };

    exec(cmd, execOptions, (error, stdout, stderr) => {
        if (error) {
            console.error(`Error: ${error.message}`);
            req.session.error = `Error al ejecutar script:\nMensaje:\n${error.message}\n\nSTDOUT:\n${stdout}\n\nSTDERR:\n${stderr}`;
            return res.redirect('/instances/create');
        }

        // Script succeeded, insert to DB
        const stmt = db.prepare('INSERT INTO instances (rfc, company, port, install_path) VALUES (?, ?, ?, ?)');
        stmt.run([rfc.toUpperCase(), company, port, installPath], (err) => {
            if (err) {
                console.error(err);
                req.session.error = 'Instancia creada, pero error al registrar en base de datos.';
            } else {
                req.session.success = `Instancia para ${company} (${rfc}) creada y registrada exitosamente en el puerto ${port}.`;
            }
            res.redirect('/instances');
        });
        stmt.finalize();
    });
});

// Instance Actions (restart, stop, start, update, backup)
app.post('/instances/:id/action', requireAuth, (req, res) => {
    const id = req.params.id;
    const action = req.body.action;

    db.get('SELECT * FROM instances WHERE id = ?', [id], (err, instance) => {
        if (err || !instance) {
            req.session.error = 'Instancia no encontrada.';
            return res.redirect('/instances');
        }

        const cwd = instance.install_path;
        let cmd = '';

        if (action === 'restart') cmd = 'docker compose restart';
        else if (action === 'stop') cmd = 'docker compose down';
        else if (action === 'start') cmd = 'docker compose up -d';
        else if (action === 'update') {
            cmd = 'git pull origin main && docker compose exec -T app composer install --no-dev --optimize-autoloader && docker compose exec -T app php artisan migrate --force && docker compose exec -T app php artisan optimize:clear && docker compose restart';
        }
        else if (action === 'backup') {
            const backupDir = `/home/aplicaciones/pui/backups/${instance.rfc}`;
            if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });
            
            const dateStr = new Date().toISOString().replace(/T/, '_').replace(/:/g, '-').split('.')[0];
            const file = `${backupDir}/${dateStr}.sql`;
            
            // To run mysqldump we must pass env properly, docker compose exec -T is used.
            cmd = `docker compose exec -T mysql sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > ${file}`;
            
            exec(cmd, { cwd }, (error, stdout, stderr) => {
                if (error) {
                    req.session.error = `Error al respaldar: ${error.message}`;
                } else {
                    db.run('UPDATE instances SET last_backup = CURRENT_TIMESTAMP WHERE id = ?', [id]);
                    req.session.success = `Respaldo exitoso: ${file}`;
                }
                return res.redirect('/instances');
            });
            return; // Backup async handled above
        }

        if (cmd) {
            exec(cmd, { cwd }, (error, stdout, stderr) => {
                if (error) {
                    req.session.error = `Error al ejecutar acción ${action}: ${error.message}`;
                } else {
                    let newStatus = instance.status;
                    if (action === 'stop') newStatus = 'offline';
                    if (action === 'start' || action === 'restart') newStatus = 'online';
                    
                    if (newStatus !== instance.status) {
                        db.run('UPDATE instances SET status = ? WHERE id = ?', [newStatus, id]);
                    }
                    req.session.success = `Acción ${action} ejecutada exitosamente en ${instance.rfc}.`;
                }
                res.redirect('/instances');
            });
        }
    });
});

// View Logs
app.get('/instances/:id/logs', requireAuth, (req, res) => {
    const id = req.params.id;
    const service = req.query.service || 'app'; // app, nginx, mysql

    db.get('SELECT * FROM instances WHERE id = ?', [id], (err, instance) => {
        if (err || !instance) {
            req.session.error = 'Instancia no encontrada.';
            return res.redirect('/instances');
        }

        const cwd = instance.install_path;
        const cmd = `docker compose logs --tail=100 ${service}`;

        exec(cmd, { cwd }, (error, stdout, stderr) => {
            const logs = stdout || stderr || (error ? error.message : 'No hay logs');
            res.render('logs', { logs, instance, service, title: 'Logs' });
        });
    });
});

app.listen(PORT, '0.0.0.0', () => {
    console.log(`PUI Manager corriendo en el puerto ${PORT}`);
});
