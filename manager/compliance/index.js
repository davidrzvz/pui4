const express = require('express');
const router = express.Router();
const toolRegistry = require('./engine/ToolRegistry');

// Ensure tool registry is up to date when accessing the module
router.use(async (req, res, next) => {
    await toolRegistry.checkTools();
    next();
});

// Dashboard routes
router.get('/dashboard', (req, res) => {
    res.render('compliance/dashboard', { activeMenu: 'compliance' });
});

router.get('/auditorias', (req, res) => {
    res.render('compliance/auditorias', { activeMenu: 'compliance' });
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
