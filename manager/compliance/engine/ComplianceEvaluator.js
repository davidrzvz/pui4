class ComplianceEvaluator {
    /**
     * Evaluates if the given audit results comply with a specific profile.
     * @param {Object} auditResults - The results of the audit (findings, vulnerability counts).
     * @param {String} profile - The security profile (e.g., 'Gobierno México PUI').
     * @returns {Object} { compliant: boolean, reason: string }
     */
    static evaluate(auditResults, profile) {
        if (profile === 'Gobierno México PUI') {
            // According to PUI standards, typically 0 critical and 0 high are allowed.
            const criticals = auditResults.vulnerabilities.critical || 0;
            const highs = auditResults.vulnerabilities.high || 0;

            if (criticals > 0 || highs > 0) {
                return {
                    compliant: false,
                    reason: `Found ${criticals} critical and ${highs} high vulnerabilities. "Gobierno México PUI" profile allows 0.`
                };
            }

            return {
                compliant: true,
                reason: 'No critical or high vulnerabilities found.'
            };
        }

        // Default compliance logic
        return { compliant: true, reason: 'Profile not strictly evaluated or unknown profile.' };
    }
}

module.exports = ComplianceEvaluator;
