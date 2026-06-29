const { execPromise } = require('child_process');
const util = require('util');
const exec = util.promisify(require('child_process').exec);

class ToolRegistry {
    constructor() {
        this.tools = {
            'java': { status: 'missing', version: null, path: null },
            'node': { status: 'missing', version: null, path: null },
            'php': { status: 'missing', version: null, path: null },
            'composer': { status: 'missing', version: null, path: null },
            'npm': { status: 'missing', version: null, path: null },
            'semgrep': { status: 'missing', version: null, path: null },
            'dependency-check': { status: 'missing', version: null, path: null },
            'zap-cli': { status: 'missing', version: null, path: null },
            'zaproxy': { status: 'missing', version: null, path: null },
            'playwright': { status: 'missing', version: null, path: null }
        };
    }

    async checkTools() {
        for (const tool of Object.keys(this.tools)) {
            try {
                // Get path
                const pathCmd = process.platform === 'win32' ? `where ${tool}` : `which ${tool}`;
                let pathStr = '';
                try {
                    const { stdout: pathOut } = await exec(pathCmd);
                    pathStr = pathOut.trim().split('\n')[0];
                    this.tools[tool].path = pathStr || null;
                } catch(e) {
                    this.tools[tool].path = null;
                }

                // Get version
                let cmd = `${tool} --version`;
                if (tool === 'zap-cli') cmd = `zap-cli --version`; 
                if (tool === 'zaproxy') cmd = `zaproxy -version`; 
                if (tool === 'java') cmd = `java -version 2>&1`; 
                if (tool === 'playwright') cmd = `npx playwright --version`;
                
                const { stdout, stderr } = await exec(cmd);
                
                // Very basic parsing to just grab the first line
                const output = (stdout || stderr || '').trim();
                const firstLine = output.split('\n')[0];
                
                if (firstLine && !firstLine.toLowerCase().includes('not found') && !firstLine.toLowerCase().includes('command not found')) {
                    this.tools[tool].status = 'installed';
                    this.tools[tool].version = firstLine;
                } else {
                    this.tools[tool].status = 'missing';
                    this.tools[tool].version = null;
                }
            } catch (error) {
                this.tools[tool].status = 'missing';
                this.tools[tool].version = null;
            }
        }
    }

    getToolStatus() {
        return this.tools;
    }

    isToolAvailable(toolName) {
        return this.tools[toolName] && this.tools[toolName].status === 'installed';
    }
}

module.exports = new ToolRegistry();
