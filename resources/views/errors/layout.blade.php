@php
    $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
    $cookieLocale = request()->cookie('locale');
    if (is_string($cookieLocale) && !in_array($cookieLocale, ['ar', 'en'], true)) {
        try {
            $encrypter = app('encrypter');
            $cookieLocale = \Illuminate\Cookie\CookieValuePrefix::validate('locale', $encrypter->decrypt($cookieLocale, false), $encrypter->getAllKeys());
        } catch (\Throwable) {
            $cookieLocale = null;
        }
    }
    if (in_array($cookieLocale, ['ar', 'en'], true)) {
        $locale = $cookieLocale;
    }
    $ar = $locale === 'ar';
    $copy = [
        403 => ['Access denied', 'ليس لديك صلاحية للوصول إلى هذه الصفحة', 'Your account does not have the permission needed to open this page.', 'لا يملك حسابك الصلاحية المطلوبة لفتح هذه الصفحة.'],
        404 => ['Page not found', 'الصفحة التي تبحث عنها غير موجودة', 'The page may have moved, or the link may no longer be available.', 'ربما تم نقل الصفحة أو لم يعد الرابط متاحًا.'],
        419 => ['Session expired', 'انتهت صلاحية الجلسة', 'Sign in again, then review your work before submitting. Your last request has not been retried.', 'سجل الدخول مجددًا ثم راجع بياناتك قبل إرسالها. لم تتم إعادة إرسال طلبك الأخير.'],
        422 => ['Unable to process this request', 'تعذر معالجة البيانات المدخلة', 'Please return to the form and review the information you entered.', 'يرجى العودة إلى النموذج ومراجعة البيانات التي أدخلتها.'],
        429 => ['A moment, please', 'تم تجاوز عدد المحاولات المسموح بها', 'Please wait a little before trying again.', 'يرجى الانتظار قليلًا قبل المحاولة مجددًا.'],
        500 => ['Something went wrong', 'حدث خطأ غير متوقع', 'We could not complete this request. Please try again later. Check the document status before submitting again.', 'تعذر إكمال الطلب. يرجى المحاولة لاحقًا والتحقق من حالة المستند قبل إرساله مجددًا.'],
        503 => ['We will be back soon', 'الخدمة غير متاحة مؤقتًا', 'The service is temporarily unavailable. Please return in a little while.', 'الخدمة غير متاحة مؤقتًا. يرجى العودة بعد قليل.'],
    ][$code];
    $heading = $copy[$ar ? 1 : 0];
    $description = $copy[$ar ? 3 : 2];
    $home = url('/');
    $primaryUrl = $code === 419 ? route('filament.admin.auth.login') : $home;
    $primaryLabel = $code === 419 ? ($ar ? 'تسجيل الدخول مجددًا' : 'Sign in again') : ($ar ? 'العودة للرئيسية' : 'Return home');
    if ($code === 403) {
        try {
            $user = request()->user();
            if ($user !== null && $user->is_active && ($user->isGlobalSuperAdmin() || $user->hasPanelPermissions())) {
                $primaryUrl = route('filament.admin.pages.dashboard');
                $primaryLabel = $ar ? 'لوحة التحكم' : 'Return to dashboard';
            }
        } catch (\Throwable) {
            // Safe navigation remains available when account services are unavailable.
        }
    }
    $back = request()->headers->get('referer');
    $backPath = $back ? parse_url($back, PHP_URL_PATH) : null;
    $safeBack = $back && parse_url($back, PHP_URL_HOST) === request()->getHost()
        && parse_url($back, PHP_URL_SCHEME) === request()->getScheme()
        && is_string($backPath) && str_starts_with($backPath, '/') && !str_starts_with($backPath, '//') && !str_contains($backPath, '\\');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ $code }} · {{ $heading }} · Fox &amp; Hema</title>
    <style>
        :root{color-scheme:light dark;--navy:#132441;--teal:#12B8A6;--bg:#F4F7FB;--surface:#fff;--ink:#132441;--muted:#526078;--border:#dce5ee}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif;min-height:100svh;display:flex;flex-direction:column}header,footer{padding:28px clamp(24px,6vw,88px)}header{display:flex;align-items:center;gap:12px}.mark{width:40px;height:40px;background:var(--navy);color:#fff;display:grid;place-items:center;border-radius:13px;font-weight:700;box-shadow:inset 0 -4px 0 var(--teal)}.brand{font-size:20px;font-weight:700;letter-spacing:.02em}.brand small{display:block;color:var(--muted);font-size:10px;letter-spacing:.19em;margin-top:5px}main{flex:1;display:grid;place-items:center;padding:24px}article{width:min(100%,920px);display:grid;grid-template-columns:1fr 1.3fr;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:28px;box-shadow:0 24px 80px #1324410b;overflow:hidden}.art{position:relative;min-height:400px;display:grid;place-content:center;text-align:center;background:linear-gradient(150deg,#12b8a612,#13244108);padding:30px}.art svg{width:180px;height:120px;margin:auto}.code{font-size:clamp(76px,9vw,116px);font-weight:700;line-height:1.1;letter-spacing:-.06em;color:var(--ink)}.content{padding:48px 40px}.eyebrow{color:#087b70;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase}h1{font-size:clamp(23px,3vw,32px);line-height:1.5;margin:16px 0}p{color:var(--muted);font-size:15px;line-height:1.9;margin:0 0 28px}.actions{display:flex;gap:12px;flex-wrap:wrap}a{border-radius:11px;min-height:46px;padding:13px 19px;font-size:14px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;justify-content:center}.primary{background:var(--navy);color:#fff;box-shadow:inset 0 -3px 0 var(--teal)}.secondary{color:var(--ink);border:1px solid var(--border)}a:focus-visible{outline:3px solid var(--teal);outline-offset:4px}a:hover{filter:brightness(1.12)}footer{text-align:center;color:var(--muted);font-size:12px}.status{display:inline-flex;gap:7px;align-items:center}.status:before{content:'';height:6px;width:6px;border-radius:50%;background:var(--teal)}@media(max-width:640px){header,footer{padding:22px 24px}main{padding:12px 20px}article{grid-template-columns:1fr;border-radius:22px}.art{min-height:190px;padding:20px}.art svg{width:112px;height:65px}.code{font-size:76px}.content{padding:28px 24px}.actions a{flex:1}}@media(prefers-color-scheme:dark){:root{--bg:#0c1525;--surface:#132441;--ink:#eef5ff;--muted:#b1c0d4;--border:#2c405e}.eyebrow{color:#63e4d2}.primary{background:#0c1525}.art{background:linear-gradient(150deg,#12b8a619,#0c152533)}}
    </style>
</head>
<body>
    <header><span class="mark" aria-hidden="true">F<span hidden>&amp;H</span></span><div class="brand" dir="ltr">Fox &amp; Hema<small>ENTERPRISE RESOURCE PLANNING</small></div></header>
    <main>
        <article aria-labelledby="error-heading">
            <div class="art" aria-hidden="true">
                <svg viewBox="0 0 180 120" fill="none"><circle cx="90" cy="60" r="51" stroke="#12B8A6" stroke-opacity=".24" stroke-width="2"/><rect x="56" y="23" width="68" height="76" rx="12" fill="var(--surface)" stroke="#12B8A6" stroke-width="2"/><path d="M73 46h34M73 59h23M73 72h13" stroke="#12B8A6" stroke-width="4" stroke-linecap="round"/><circle cx="126" cy="87" r="17" fill="#132441"/><path d="M126 79v9m0 5v1" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="m34 29 5 5-5 5-5-5 5-5Zm110-13 4 4-4 4-4-4 4-4Z" fill="#12B8A6"/></svg>
                <div class="code">{{ $code }}</div>
            </div>
            <div class="content">
                <span class="eyebrow">{{ $ar ? 'فوكس وهيما · نظام إدارة الأعمال' : 'Fox & Hema · Workspace' }}</span>
                <h1 id="error-heading">{{ $heading }}</h1>
                <p>{{ $description }}</p>
                <nav class="actions" aria-label="{{ $ar ? 'خيارات التنقل' : 'Recovery actions' }}">
                    <a class="primary" href="{{ $primaryUrl }}">{{ $primaryLabel }}</a>
                    @if($code !== 419 && ($safeBack || in_array($code, [403, 404], true)))
                        <a class="secondary" href="{{ $safeBack ? $backPath : $home }}">{{ $ar ? 'العودة' : 'Go back' }}</a>
                    @elseif($code === 419)
                        <a class="secondary" href="{{ $home }}">{{ $ar ? 'الرئيسية' : 'Home' }}</a>
                    @endif
                </nav>
            </div>
        </article>
    </main>
    <footer><span class="status">Fox &amp; Hema ERP</span></footer>
</body>
</html>
