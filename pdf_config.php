<?php
// pdf_config.php - putanje za PDF obradu (bez tajni; OpenAI ključ je u .env alata)
use App\Services\PdfJobStore;

if (PHP_OS_FAMILY === 'Windows') {
    define('PDF_JOBS_ROOT', 'D:/pdf_jobs');
    define('PDF_TOOL_DIR', 'D:/claude/pdf');
    define('PDF_PYTHON', 'python');
    define('PDF_USE_WORKER', false);   // lokalno PHP sam pokreće runner
} else {
    $home = getenv('HOME') ?: dirname(__DIR__, 4);   // /home/danko1 (ap/ je 4 razine ispod)
    // open_basedir dopušta ~/web/j-sistem.hr/private (izvan weba); ~/pdf_jobs ne
    define('PDF_JOBS_ROOT', $home . '/web/j-sistem.hr/private/pdf_jobs');
    define('PDF_TOOL_DIR', $home . '/apps/pdf-a11y');
    define('PDF_PYTHON', $home . '/apps/pdf-a11y/venv/bin/python');
    // disable_functions zabranjuje proc_open — runner pokreće job_worker.py iz crona
    define('PDF_USE_WORKER', true);
}

function pdf_job_store(): PdfJobStore
{
    if (!is_dir(PDF_JOBS_ROOT)) {
        mkdir(PDF_JOBS_ROOT, 0700, true);
    }
    return new PdfJobStore(PDF_JOBS_ROOT, PDF_USE_WORKER ? PdfJobStore::requestLauncher(PDF_JOBS_ROOT) : null);
}
