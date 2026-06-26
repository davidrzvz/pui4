const express = require('express');
const router = express.Router();

// Dashboard routes
router.get('/dashboard', (req, res) => {
    res.render('compliance/dashboard', { activeMenu: 'compliance' });
});

router.get('/auditorias', (req, res) => {
    res.render('compliance/auditorias', { activeMenu: 'compliance' });
});

// We will add more routes here for evidences, certified versions, tools, etc.

module.exports = router;
