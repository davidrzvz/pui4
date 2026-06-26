const { execPromise } = require('child_process');
const util = require('util');
const exec = util.promisify(require('child_process').exec);

class ToolRegistry {
    constructor() {
        this.tools = {
            'java': { installed: false, version: null, path: null },
            'node': { installed: false, version: null, path: null },
            'php': { installed: false, version: null, path: null },
            'composer': { installed: false, version: null, path: null },
            'npm': { installed: false, version: null, path: null },
            'semgrep': { installed: false, version: null, path: null },
            'dependency-check': { installed: false, version: null, path: null },
            'zap-cli': { installed: false, version: null, path: null }
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
                    this.tools[tool].path = pathStr;
                } catch(e) { }

                // Get version
                let cmd = `${tool} --version`;
                if (tool === 'zap-cli') cmd = `zap-cli --version`; 
                if (tool === 'java') cmd = `java -version 2>&1`; 
                
                const { stdout } = await exec(cmd);
                this.tools[tool].installed = true;
                
                // Very basic parsing to just grab the first line
                this.tools[tool].version = stdout.trim().split('\n')[0];
            } catch (error) {
                this.tools[tool].installed = false;
                this.tools[tool].version = null;
            }
        }
    }

    getToolStatus() {
        return this.tools;
    }

    isToolAvailable(toolName) {
        return this.tools[toolName] && this.tools[toolName].installed;
    }
}

module.exports = new ToolRegistry();
