<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gangguan sementara</title>
</head>
<body>
<script>
    (function () {
        var key = 'sikeu_500_reload';
        var cooldownKey = 'sikeu_500_reload_at';
        try {
            var now = Date.now();
            var last = parseInt(sessionStorage.getItem(cooldownKey) || '0', 10);
            // Hindari reload beruntun yang memperparah beban server
            if (!sessionStorage.getItem(key) && (now - last) > 5000) {
                sessionStorage.setItem(key, '1');
                sessionStorage.setItem(cooldownKey, String(now));
                setTimeout(function () {
                    window.location.reload();
                }, 1200);
                return;
            }
            sessionStorage.removeItem(key);
        } catch (e) {}
    })();
</script>
<div style="font-family: system-ui, sans-serif; padding: 2rem; text-align: center;">
    <h1 style="font-size: 1.25rem;">Terjadi gangguan sementara</h1>
    <p>Silakan muat ulang halaman beberapa detik lagi.</p>
</div>
</body>
</html>
