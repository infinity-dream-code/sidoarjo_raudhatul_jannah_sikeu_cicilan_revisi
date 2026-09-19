/**
 * Format No. VA: prefix + NIS (total 16 digit).
 * VA Close (tidak cicil): 797789
 * VA Open (cicil): 797790
 */
(function (window) {
    const DEFAULT_PREFIX_CLOSE = '797789';
    const DEFAULT_PREFIX_OPEN = '797790';
    const TOTAL_LENGTH = 16;

    window.formatNoVA = function (nis, prefix) {
        const vaPrefix = String(prefix ?? window.APP_VA_PREFIX ?? DEFAULT_PREFIX_CLOSE).replace(/\D/g, '') || DEFAULT_PREFIX_CLOSE;
        const digits = String(nis ?? '').replace(/\D/g, '');
        if (!digits) {
            return '';
        }
        const padLen = Math.max(1, TOTAL_LENGTH - vaPrefix.length);
        return vaPrefix + digits.padStart(padLen, '0');
    };

    window.formatNoVAByInstallable = function (nis, isInstallable) {
        const open = String(window.APP_VA_PREFIX_OPEN ?? DEFAULT_PREFIX_OPEN).replace(/\D/g, '') || DEFAULT_PREFIX_OPEN;
        const close = String(window.APP_VA_PREFIX_CLOSE ?? window.APP_VA_PREFIX ?? DEFAULT_PREFIX_CLOSE).replace(/\D/g, '') || DEFAULT_PREFIX_CLOSE;
        return window.formatNoVA(nis, Number(isInstallable) === 1 ? open : close);
    };
})(window);
