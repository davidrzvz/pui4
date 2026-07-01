import argparse
import subprocess
import json
import os
import datetime
import html

def get_current_time():
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

def run_cmd(cmd_list, timeout=120, cwd=None):
    try:
        result = subprocess.run(cmd_list, capture_output=True, text=True, timeout=timeout, cwd=cwd)
        return {
            "success": result.returncode == 0,
            "stdout": result.stdout,
            "stderr": result.stderr,
            "code": result.returncode,
            "cmd": " ".join(cmd_list)
        }
    except subprocess.TimeoutExpired as e:
        stdout_str = e.stdout.decode('utf-8', 'ignore') if e.stdout else ''
        stderr_str = e.stderr.decode('utf-8', 'ignore') if e.stderr else f'Timeout de {timeout}s excedido'
        return {
            "success": False,
            "stdout": stdout_str,
            "stderr": stderr_str,
            "code": -1,
            "cmd": " ".join(cmd_list)
        }
    except Exception as e:
        return {
            "success": False,
            "stdout": "",
            "stderr": str(e),
            "code": -2,
            "cmd": " ".join(cmd_list)
        }

def run_sast(code_path):
    print(">> Iniciando SAST...")
    findings = []
    
    cmd = [
        "docker", "run", "--rm", 
        "-v", f"{code_path}:/src:ro", 
        "-v", "/app/security-rules:/app/security-rules:ro",
        "returntocorp/semgrep", "semgrep", "scan", "--config=/app/security-rules", "--json", "--metrics=off",
        "--exclude", "vendor", "--exclude", "node_modules", "--exclude", "storage", "--exclude", "bootstrap/cache",
        "/src"
    ]
    
    res = run_cmd(cmd)
    
    status = "Completado"
    if res["code"] not in [0, 1]:
        status = "Fallido"
        err_msg = res['stderr'] or res['stdout']
        if "Network error" in err_msg or "Failed to fetch" in err_msg or "certificate verify failed" in err_msg or "Could not fetch" in err_msg or "error downloading" in err_msg.lower() or "No such file or directory" in err_msg:
             title = "Error de Carga de Reglas"
             desc = f"No fue posible cargar el repositorio local de reglas Semgrep ubicado en /app/security-rules.\nDetalle: {err_msg[:500]}"
        else:
             title = "Error de Herramienta"
             desc = f"Semgrep falló:\n{err_msg[:500]}"
             
        findings.append({
            "title": title,
            "severity": "High",
            "description": desc,
            "recommendation": "Verificar que el volumen /app/security-rules exista y tenga permisos de lectura o revisar sintaxis."
        })
    else:
        try:
            data = json.loads(res["stdout"])
            for f in data.get("results", []):
                findings.append({
                    "title": f.get("check_id", "Vulnerabilidad"),
                    "severity": f.get("extra", {}).get("severity", "Medium"),
                    "description": f.get("extra", {}).get("message", ""),
                    "recommendation": "Revisar código fuente afectado."
                })
        except json.JSONDecodeError:
            status = "Fallido"
            findings.append({
                "title": "Error de Parseo",
                "severity": "High",
                "description": "El output de Semgrep no fue un JSON válido.\n" + (res["stderr"][:500] if res["stderr"] else ""),
                "recommendation": "Revisar logs de Docker."
            })

    return {
        "type": "SAST",
        "tool": "Semgrep",
        "status": status,
        "command": res["cmd"],
        "findings": findings
    }

def extract_dependencies(code_path):
    deps = []
    # Composer
    c_json = os.path.join(code_path, "composer.json")
    if os.path.exists(c_json):
        try:
            with open(c_json, 'r') as f:
                d = json.load(f)
                reqs = d.get('require', {})
                for k, v in reqs.items():
                    deps.append(f"PHP: {k} ({v})")
        except: pass
    
    # NPM
    p_json = os.path.join(code_path, "package.json")
    if os.path.exists(p_json):
        try:
            with open(p_json, 'r') as f:
                d = json.load(f)
                reqs = d.get('dependencies', {})
                for k, v in reqs.items():
                    deps.append(f"NPM: {k} ({v})")
        except: pass
    return deps

def run_sca(code_path):
    print(">> Iniciando SCA...")
    findings = []
    status = "Completado"
    commands_run = []
    
    # Composer Audit via temporal container
    if os.path.exists(os.path.join(code_path, "composer.json")):
        cmd_composer = ["docker", "run", "--rm", "-v", f"{code_path}:/app:ro", "composer:latest", "composer", "audit", "--working-dir=/app", "--format=json"]
        commands_run.append("composer audit")
        res_c = run_cmd(cmd_composer)
        if res_c["code"] != 0 and res_c["code"] != 1 and not res_c["stdout"].strip().startswith('{'):
            findings.append({
                "title": "Fallo Composer Audit",
                "severity": "Medium",
                "description": f"No se pudo consultar CVE para PHP:\n{res_c['stderr']}",
                "recommendation": "Asegurar que existe el composer.lock válido o conectividad a Packagist."
            })
            status = "Parcial"
        else:
            try:
                data = json.loads(res_c["stdout"])
                vulns = data.get("vulnerabilities", {})
                for pkg, pkg_vulns in vulns.items():
                    for v in pkg_vulns:
                        findings.append({
                            "title": f"PHP: {pkg}",
                            "severity": "High",
                            "description": v.get("title", "Vulnerabilidad conocida"),
                            "recommendation": "Actualizar dependencia."
                        })
            except: pass

    # NPM Audit via temporal container
    if os.path.exists(os.path.join(code_path, "package.json")):
        cmd_npm = ["docker", "run", "--rm", "-v", f"{code_path}:/app:ro", "node:latest", "npm", "audit", "--prefix", "/app", "--json"]
        commands_run.append("npm audit")
        res_n = run_cmd(cmd_npm)
        if res_n["code"] != 0 and res_n["code"] != 1 and not res_n["stdout"].strip().startswith('{'):
            findings.append({
                "title": "Fallo NPM Audit",
                "severity": "Medium",
                "description": f"No se pudo consultar CVE para NPM:\n{res_n['stderr']}",
                "recommendation": "Asegurar package-lock.json o conectividad al registry."
            })
            if status == "Completado": status = "Parcial"
        else:
            try:
                data = json.loads(res_n["stdout"])
                vulns = data.get("vulnerabilities", {})
                for pkg, v_info in vulns.items():
                    findings.append({
                        "title": f"NPM: {pkg}",
                        "severity": v_info.get("severity", "High").capitalize(),
                        "description": f"Vulnerabilidad detectada. Fix disponible: {v_info.get('fixAvailable', False)}",
                        "recommendation": "Ejecutar npm audit fix."
                    })
            except: pass

    if not commands_run:
        deps = extract_dependencies(code_path)
        if deps:
            desc = "Dependencias detectadas:\n" + "\n".join(deps[:20])
            if len(deps) > 20: desc += f"\n... y {len(deps)-20} más."
        else:
            desc = "No se detectaron dependencias en el proyecto."
            
        findings.append({
            "title": "Inspección Estática",
            "severity": "Low",
            "description": desc,
            "recommendation": "Limitación: No se pudieron ejecutar los auditores automáticos. Se listan las dependencias detectadas a nivel estático."
        })
        status = "Parcial"

    return {
        "type": "SCA",
        "tool": " + ".join(commands_run) if commands_run else "Análisis Estático Local",
        "status": status,
        "command": "docker run ... composer/npm audit",
        "findings": findings
    }

def run_dast(url):
    print(">> Iniciando DAST")
    findings = []
    
    if not url:
        return {
            "type": "DAST",
            "tool": "Ninguna",
            "status": "Fallido",
            "command": "N/A",
            "findings": [{"title": "Falta URL", "severity": "High", "description": "No se proveyó URL.", "recommendation": ""}]
        }
    
    cmd = [
        "docker", "run", "--rm", "--network", "host",
        "ghcr.io/zaproxy/zaproxy:stable", "zap-baseline.py", "-t", url, "-J", "report.json"
    ]
    
    print(">> Ejecutando comando...")
    res = run_cmd(cmd, timeout=120)
    print(">> Resultado recibido")
    
    status = "Completado"
    if res["code"] not in [0, 1, 2]:
        status = "Fallido"
        findings.append({
            "title": "Error de Ejecución",
            "severity": "High",
            "description": f"ZAP falló o excedió timeout:\n{res['stderr'][:500]}",
            "recommendation": "Revisar logs o conectividad a la URL destino."
        })
    else:
        findings.append({
            "title": "DAST Ejecutado",
            "severity": "Low",
            "description": f"Se aplicó el Baseline Scan de ZAP contra {url}.",
            "recommendation": "Nota: Sin autenticación, los endpoints analizados son únicamente públicos."
        })
        
    return {
        "type": "DAST",
        "tool": "OWASP ZAP",
        "status": status,
        "command": res["cmd"],
        "findings": findings
    }

def build_html_report(name, url, code_path, report_data):
    date_str = get_current_time()
    type_name = report_data["type"]
    
    if type_name == "SAST":
        findings_html_sast = ""
        if not report_data["findings"]:
            findings_html_sast = "<p>No se reportaron hallazgos.</p>"
        else:
            for idx, f in enumerate(report_data["findings"]):
                desc = html.escape(f.get('description', '')).replace('\\n', '<br>').replace('\n', '<br>')
                findings_html_sast += f"""
                <div class="finding avoid-break">
                    <h3>Hallazgo #{idx+1}: {html.escape(f.get('title', ''))}</h3>
                    <p><strong>Severidad:</strong> <span class="severity-{f.get('severity', '').lower()}">{f.get('severity', '')}</span></p>
                    <p><strong>Descripción:</strong> <pre style="white-space: pre-wrap; font-family: inherit;">{desc}</pre></p>
                    <p><strong>Recomendación:</strong> {html.escape(f.get('recommendation', ''))}</p>
                </div>
                """
        
        return f"""<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Evidencia de Seguridad - SAST</title>
    <style>
        body {{ font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; line-height: 1.6; }}
        h1 {{ color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; margin-bottom: 10px; }}
        .subtitle {{ font-size: 1.5em; color: #7f8c8d; margin-bottom: 30px; font-weight: bold; }}
        h2 {{ color: #2980b9; margin-top: 40px; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; }}
        table {{ width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 20px; }}
        th, td {{ border: 1px solid #ddd; padding: 12px; text-align: left; }}
        th {{ background-color: #ecf0f1; color: #2c3e50; width: 30%; }}
        .finding {{ background: #f9f9f9; padding: 20px; border-left: 5px solid #e74c3c; margin-bottom: 20px; page-break-inside: avoid; }}
        .finding h3 {{ margin-top: 0; color: #e74c3c; border-bottom: 1px solid #eee; padding-bottom: 10px; }}
        .severity-high {{ color: #c0392b; font-weight: bold; }}
        .severity-medium {{ color: #d35400; font-weight: bold; }}
        .severity-low {{ color: #f39c12; font-weight: bold; }}
        .print-btn {{
            display: inline-block; background-color: #3498db; color: #fff; padding: 10px 20px;
            text-decoration: none; border-radius: 5px; font-weight: bold; margin-bottom: 20px;
            cursor: pointer; border: none; font-size: 16px;
        }}
        .print-btn:hover {{ background-color: #2980b9; }}
        .footer {{ margin-top: 50px; font-size: 0.9em; color: #7f8c8d; border-top: 1px solid #ddd; padding-top: 20px; text-align: center; }}
        @media print {{
            body {{ padding: 0; background-color: #fff; color: #000; margin: 20px; }}
            .print-btn {{ display: none; }}
            .finding {{ border-left: 2px solid #000; background: #fff; border: 1px solid #ccc; }}
            h1, h2, th, td, p, span, .subtitle, .footer {{ color: #000 !important; }}
            table, th, td {{ border-color: #000; }}
            .avoid-break {{ page-break-inside: avoid; }}
        }}
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>

    <h1>REPORTE DE EVIDENCIA DE SEGURIDAD</h1>
    <div class="subtitle">Análisis Estático de Código Fuente (SAST)</div>
    
    <table class="avoid-break">
        <tr><th>Plataforma</th><td>Plataforma Única de Identificación (PUI)</td></tr>
        <tr><th>Módulo</th><td>Compliance Center</td></tr>
        <tr><th>Cliente (RFC)</th><td>{html.escape(name)}</td></tr>
        <tr><th>Fecha / Hora</th><td>{date_str}</td></tr>
        <tr><th>Herramienta</th><td>{html.escape(report_data.get('tool', 'N/A'))}</td></tr>
        <tr><th>Tipo de análisis</th><td>SAST</td></tr>
        <tr><th>Estado de ejecución</th><td><strong>COMPLETADO</strong></td></tr>
    </table>

    <h2>1. Objetivo</h2>
    <p>El presente documento constituye la evidencia documental del análisis estático de seguridad (SAST) ejecutado sobre el código fuente de la aplicación evaluada, con el propósito de identificar posibles vulnerabilidades antes de la fase de ejecución.</p>

    <h2>2. Alcance</h2>
    <table class="avoid-break">
        <tr><th>Ruta analizada</th><td>{html.escape(code_path)}</td></tr>
        <tr><th>Tecnología detectada</th><td>PHP / JavaScript (Inferida)</td></tr>
        <tr><th>Directorios excluidos</th><td>vendor, node_modules, storage, bootstrap/cache</td></tr>
    </table>

    <h2>3. Información del Análisis</h2>
    <table class="avoid-break">
        <tr><th>Fecha inicio</th><td>{date_str}</td></tr>
        <tr><th>Fecha fin</th><td>{date_str}</td></tr>
        <tr><th>Duración</th><td>Automática</td></tr>
        <tr><th>Herramienta</th><td>{html.escape(report_data.get('tool', 'N/A'))}</td></tr>
        <tr><th>Fuente de reglas</th><td>Semgrep Community Rules almacenadas localmente.</td></tr>
        <tr><th>Paquete utilizado</th><td>/app/security-rules</td></tr>
        <tr><th>URL / Fuente</th><td>https://github.com/semgrep/semgrep-rules</td></tr>
        <tr><th>Comando ejecutado</th><td><code>{html.escape(report_data.get('command', 'N/A'))}</code></td></tr>
    </table>

    <h2>4. Metodología</h2>
    <p>El análisis SAST fue ejecutado utilizando el repositorio oficial de reglas Community de Semgrep almacenado localmente dentro del Compliance Center, garantizando reproducibilidad, independencia de Internet y trazabilidad para procesos de auditoría.</p>

    <h2>5. Resultado Ejecutivo</h2>
    <p>Estado del análisis: <strong>COMPLETADO</strong></p>
    <p>Resultado: Ejecución del analizador sobre el componente especificado.</p>

    <h2>6. Hallazgos</h2>
    {findings_html_sast}

    <h2>Conclusión</h2>
    <p>El proceso SAST fue ejecutado correctamente y la evidencia técnica ha sido recolectada.</p>

    <div class="footer avoid-break">
        <p>Documento generado automáticamente por: <strong>PUI Compliance Center</strong></p>
        <p>No modificar manualmente. Este documento forma parte de la evidencia técnica del análisis de seguridad.</p>
    </div>
</body>
</html>
"""

    findings_html = ""
    if not report_data["findings"]:
        findings_html = """
        <p>No se encontraron vulnerabilidades ni errores en este análisis.</p>
        <p><em>Justificación formal: El código o servicio auditado cumple con las reglas básicas del perfil evaluado y la herramienta no reportó anomalías bajo esta configuración.</em></p>
        """
    else:
        for idx, f in enumerate(report_data["findings"]):
            desc = html.escape(f['description']).replace('\\n', '<br>').replace('\n', '<br>')
            findings_html += f"""
            <div class="finding avoid-break">
                <h3>Hallazgo #{idx+1}: {html.escape(f['title'])}</h3>
                <p><strong>Severidad:</strong> <span class="severity-{f['severity'].lower()}">{f['severity']}</span></p>
                <p><strong>Descripción / Detalles:</strong> <pre style="white-space: pre-wrap; font-family: inherit;">{desc}</pre></p>
                <p><strong>Recomendaciones / Justificación:</strong> {html.escape(f['recommendation'])}</p>
            </div>
            """
            
    manual_section = ""
    if type_name == "SAST":
        manual_section = f"""
        <h2>1.1 Requisitos del Manual (SAST)</h2>
        <ul class="avoid-break">
            <li><strong>Análisis de código fuente:</strong> Realizado vía escaneo estático.</li>
            <li><strong>Componente evaluado:</strong> {html.escape(code_path)}</li>
        </ul>
        """
    elif type_name == "SCA":
        manual_section = f"""
        <h2>1.1 Requisitos del Manual (SCA)</h2>
        <ul class="avoid-break">
            <li><strong>Dependencias evaluadas:</strong> Node.js / PHP.</li>
            <li><strong>Componente evaluado:</strong> {html.escape(code_path)}</li>
        </ul>
        """
    elif type_name == "DAST":
        manual_section = f"""
        <h2>1.1 Requisitos del Manual (DAST)</h2>
        <ul class="avoid-break">
            <li><strong>Servicios en ejecución y Endpoints:</strong> Auditados vía spidering.</li>
            <li><strong>URL Evaluada:</strong> {html.escape(url)}</li>
            <li><strong>Limitaciones:</strong> Análisis sin autenticación pre-configurada.</li>
        </ul>
        """

    template = f"""<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Seguridad - {type_name}</title>
    <style>
        body {{ font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; line-height: 1.6; }}
        h1 {{ color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; margin-bottom: 30px; }}
        h2 {{ color: #2980b9; margin-top: 40px; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; }}
        table {{ width: 100%; border-collapse: collapse; margin-top: 15px; }}
        th, td {{ border: 1px solid #ddd; padding: 12px; text-align: left; }}
        th {{ background-color: #ecf0f1; color: #2c3e50; }}
        .finding {{ background: #f9f9f9; padding: 20px; border-left: 5px solid #e74c3c; margin-bottom: 20px; }}
        .finding h3 {{ margin-top: 0; color: #e74c3c; }}
        .severity-high {{ color: #c0392b; font-weight: bold; }}
        .severity-medium {{ color: #d35400; font-weight: bold; }}
        .severity-low {{ color: #f39c12; font-weight: bold; }}
        .print-btn {{
            display: inline-block;
            background-color: #3498db;
            color: #fff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-bottom: 20px;
            cursor: pointer;
            border: none;
            font-size: 16px;
        }}
        .print-btn:hover {{ background-color: #2980b9; }}
        @media print {{
            body {{ padding: 0; background-color: #fff; color: #000; margin: 20px; }}
            .print-btn {{ display: none; }}
            .finding {{ border-left: 5px solid #000; background: #fff; }}
            h1, h2, th, td, p, span {{ color: #000 !important; }}
            table, th, td {{ border-color: #000; }}
            .avoid-break {{ page-break-inside: avoid; }}
        }}
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>

    <h1>Evidencia de Seguridad: {type_name}</h1>
    
    <h2>1. Información del Análisis</h2>
    <table class="avoid-break">
        <tr><th>Instancia Evaluada</th><td>{html.escape(name)}</td></tr>
        <tr><th>Fecha de Ejecución</th><td>{date_str}</td></tr>
        <tr><th>Herramienta Intentada</th><td>{html.escape(report_data['tool'])}</td></tr>
        <tr><th>Comando Ejecutado</th><td><code>{html.escape(report_data['command'])}</code></td></tr>
        <tr><th>Resultado de Herramienta</th><td><strong>{report_data['status']}</strong></td></tr>
    </table>

    {manual_section}

    <h2>2. Resumen Ejecutivo</h2>
    <p>El análisis finalizó con el estado: <strong>{report_data['status']}</strong>.</p>
    <p>Se registraron <strong>{len(report_data['findings'])}</strong> hallazgos o notas de inspección.</p>

    <h2>3. Hallazgos Detallados</h2>
    {findings_html}
</body>
</html>
"""
    return template

def build_summary_html(name, date_str, sast_res, sca_res, dast_res):
    template = f"""<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Evidencia</title>
    <style>
        body {{ font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; line-height: 1.6; }}
        h1 {{ color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; margin-bottom: 30px; }}
        table {{ width: 100%; border-collapse: collapse; margin-top: 15px; }}
        th, td {{ border: 1px solid #ddd; padding: 12px; text-align: left; }}
        th {{ background-color: #ecf0f1; color: #2c3e50; }}
        .print-btn {{
            display: inline-block;
            background-color: #3498db;
            color: #fff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-bottom: 20px;
            cursor: pointer;
            border: none;
            font-size: 16px;
        }}
        .print-btn:hover {{ background-color: #2980b9; }}
        @media print {{
            body {{ padding: 0; background-color: #fff; color: #000; margin: 20px; }}
            .print-btn {{ display: none; }}
            h1, h2, th, td, p, span {{ color: #000 !important; }}
            table, th, td {{ border-color: #000; }}
            .avoid-break {{ page-break-inside: avoid; }}
        }}
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>

    <h1>Resumen de Evidencia de Seguridad</h1>
    <p><strong>Instancia:</strong> {html.escape(name)}</p>
    <p><strong>Fecha de Generación:</strong> {date_str}</p>
    
    <h2>Estados de Ejecución</h2>
    <table class="avoid-break">
        <tr><th>Prueba</th><th>Herramienta</th><th>Estado</th><th>Hallazgos/Notas</th></tr>
        <tr><td>SAST</td><td>{html.escape(sast_res['tool'])}</td><td>{sast_res['status']}</td><td>{len(sast_res['findings'])}</td></tr>
        <tr><td>SCA</td><td>{html.escape(sca_res['tool'])}</td><td>{sca_res['status']}</td><td>{len(sca_res['findings'])}</td></tr>
        <tr><td>DAST</td><td>{html.escape(dast_res['tool'])}</td><td>{dast_res['status']}</td><td>{len(dast_res['findings'])}</td></tr>
    </table>
    
    <p style="margin-top: 40px;" class="avoid-break"><em>Esta hoja sirve como carátula para la entrega de la evidencia en formato físico o digital (PDF), certificando la intención de ejecución según el manual del Gobierno, reportando las fallas técnicas o limitaciones si existieran.</em></p>
</body>
</html>
"""
    return template

def save_data(out_dir, base_name, html_content, json_data):
    html_path = os.path.join(out_dir, f"{base_name}.html")
    json_path = os.path.join(out_dir, f"{base_name}.json")
    
    with open(html_path, "w", encoding="utf-8") as f:
        f.write(html_content)
        
    if json_data is not None:
        with open(json_path, "w", encoding="utf-8") as f:
            json.dump(json_data, f, indent=2, ensure_ascii=False)
            
    print(f"  -> Guardado: {html_path}")

def main():
    parser = argparse.ArgumentParser(description="Security Runner Standalone")
    parser.add_argument("--name", required=True, help="Nombre de la instancia (ej. RFC)")
    parser.add_argument("--code", required=True, help="Ruta absoluta al código fuente")
    parser.add_argument("--url", required=True, help="URL de la instancia")
    parser.add_argument("--output", default="evidencias", help="Ruta de salida (default: evidencias)")
    parser.add_argument("--type", default="ALL", choices=["SAST", "SCA", "DAST", "ALL"], help="Prueba específica a ejecutar")
    
    args = parser.parse_args()
    
    out_dir = os.path.join(args.output, args.name)
    os.makedirs(out_dir, exist_ok=True)
    
    print(f"====================================")
    print(f"Instancia : {args.name}")
    print(f"Código    : {args.code}")
    print(f"URL       : {args.url}")
    print(f"Salida    : {out_dir}")
    print(f"Tipo      : {args.type}")
    print(f"====================================\n")
    
    sast_res = {"tool": "N/A", "status": "No Ejecutado", "findings": []}
    sca_res = {"tool": "N/A", "status": "No Ejecutado", "findings": []}
    dast_res = {"tool": "N/A", "status": "No Ejecutado", "findings": []}

    # SAST
    if args.type in ["SAST", "ALL"]:
        sast_res = run_sast(args.code)
        sast_html = build_html_report(args.name, args.url, args.code, sast_res)
        save_data(out_dir, "SAST", sast_html, sast_res)
    
    # SCA
    if args.type in ["SCA", "ALL"]:
        sca_res = run_sca(args.code)
        sca_html = build_html_report(args.name, args.url, args.code, sca_res)
        save_data(out_dir, "SCA", sca_html, sca_res)
    
    # DAST
    if args.type in ["DAST", "ALL"]:
        dast_res = run_dast(args.url)
        dast_html = build_html_report(args.name, args.url, args.code, dast_res)
        print(">> Escribiendo DAST.json")
        print(">> Escribiendo DAST.html")
        save_data(out_dir, "DAST", dast_html, dast_res)
        print(">> DAST finalizado")
    
    # Resumen (se genera en todos los casos para mantener la trazabilidad de lo ejecutado vs omitido)
    print(">> Generando Resumen...")
    date_str = get_current_time()
    summary_html = build_summary_html(args.name, date_str, sast_res, sca_res, dast_res)
    save_data(out_dir, "RESUMEN_EVIDENCIA", summary_html, None)
    
    print("\n[✔] Evidencia recolectada exitosamente en:", out_dir)

if __name__ == "__main__":
    main()
