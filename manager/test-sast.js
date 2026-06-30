const sastService = require('./security/services/SASTService');
const PdfReportGenerator = require('./security/reports/PdfReportGenerator');
const reportGenerator = new PdfReportGenerator();
const fs = require('fs');

async function test() {
    console.log('Testing SASTService...');
    
    // Create dummy vulnerable PHP code
    fs.writeFileSync('/tmp/dummy-instance/vuln.php', `<?php
        $id = $_GET['id'];
        $db->query("SELECT * FROM users WHERE id = " . $id);
        echo $_GET['xss'];
    ?>`);

    const instance = {
        id: 999,
        rfc: 'TEST999',
        company: 'Test Company',
        install_path: '/tmp/dummy-instance'
    };

    try {
        console.log('Executing SAST...');
        const rawResult = await sastService.execute(instance);
        console.log('SAST Status:', rawResult.status);
        console.log('Findings Count:', rawResult.findings.length);
        
        console.log('Generating Reports...');
        const finalResult = await reportGenerator.generate('SAST', rawResult);
        
        console.log('JSON Path:', finalResult.json_path);
        console.log('HTML Path:', finalResult.html_path);
        console.log('PDF Path:', finalResult.pdf_path);
        
        console.log('Finished successfully.');
    } catch (e) {
        console.error('Test Failed:', e);
    }
}

test();
