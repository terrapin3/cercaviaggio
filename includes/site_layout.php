<?php
declare(strict_types=1);

if (!function_exists('cvSiteBaseUrl')) {
    function cvSiteBaseUrl(): string
    {
        return rtrim(cvBaseUrl(), '/');
    }
}

if (!function_exists('cvSiteDefaultNavItems')) {
    /**
     * @return array<int,array<string,string>>
     */
    function cvSiteDefaultNavItems(string $baseUrl): array
    {
        return [
            ['key' => 'home', 'label' => 'Home', 'href' => $baseUrl . '/'],
           // ['key' => 'ricerca', 'label' => 'Ricerca', 'href' => $baseUrl . '/#ricerca'],
            ['key' => 'viaggi-popolari', 'label' => 'Viaggi popolari', 'href' => $baseUrl . '/#viaggi-popolari'],
            ['key' => 'chi-siamo', 'label' => 'Chi siamo', 'href' => $baseUrl . '/chi-siamo.php'],
            ['key' => 'mappa-fermate', 'label' => 'Mappa fermate', 'href' => $baseUrl . '/mappa-fermate.php'],
            ['key' => 'blog', 'label' => 'Blog', 'href' => $baseUrl . '/blog'],
          /*  ['key' => 'tratte-autobus', 'label' => 'Tratte autobus', 'href' => $baseUrl . '/tratte-autobus/'],
            ['key' => 'faq', 'label' => 'FAQ', 'href' => $baseUrl . '/faq.php'],*/
            ['key' => 'partner', 'label' => 'Diventa partner', 'href' => $baseUrl . '/partner.php'],
        ];
    }
}

if (!function_exists('cvRenderSiteHeader')) {
    /**
     * @param array<string,mixed> $options
     */
    function cvRenderSiteHeader(array $options = []): string
    {
        $baseUrl = cvSiteBaseUrl();
        $active = trim((string) ($options['active'] ?? ''));
        $withContactButton = !empty($options['contact_button']);

        $items = cvSiteDefaultNavItems($baseUrl);
        $navHtml = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            $label = (string) ($item['label'] ?? '');
            $href = (string) ($item['href'] ?? '#');
            $activeClass = $key !== '' && $active === $key ? ' active' : '';
            $navHtml[] = '<li class="nav-item"><a class="nav-link' . $activeClass . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
        }

        $rightHtml = '<div class="d-flex align-items-center gap-2">';
        if ($withContactButton) {
            $rightHtml .= '<a id="authContactBtn" href="' . htmlspecialchars($baseUrl . '/partner.php', ENT_QUOTES, 'UTF-8') . '" class="btn cv-account-secondary">Contattaci</a> ';
        }
        $rightHtml .= <<<HTML
              <button id="authRegisterBtn" class="btn cv-account-secondary" type="button" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="register">Registrati</button>
              <button id="authLoginBtn" class="btn cv-account-btn" type="button" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="login"><i class="bi bi-person-circle me-1"></i>Accedi</button>
            </div>
HTML;

        $navItemsHtml = implode("\n", $navHtml);
        $escapedBaseUrl = htmlspecialchars($baseUrl . '/', ENT_QUOTES, 'UTF-8');

        return <<<HTML
    <header class="mb-4 mb-lg-5">
      <nav class="navbar navbar-expand-lg cv-topbar">
        <div class="container-fluid px-0">
          <a href="{$escapedBaseUrl}" class="cv-logo text-decoration-none" aria-label="Cercaviaggio home">
            <img src="{$baseUrl}/assets/images/logo.svg" class="cv-logo-image" alt="Cercaviaggio">
          </a>
          <button class="navbar-toggler cv-nav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#cvMainNav" aria-controls="cvMainNav" aria-expanded="false" aria-label="Apri menu">
            <i class="bi bi-list"></i>
          </button>
          <div class="collapse navbar-collapse" id="cvMainNav">
            <ul class="navbar-nav cv-nav-links me-auto mb-3 mb-lg-0">
              {$navItemsHtml}
            </ul>
            {$rightHtml}
          </div>
        </div>
      </nav>
    </header>
HTML;
    }
}

if (!function_exists('cvRenderSiteAuthModals')) {
    function cvRenderSiteAuthModals(): string
    {
        return <<<'HTML'
  <div class="modal fade" id="authModal" tabindex="-1" aria-labelledby="authModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content cv-modal cv-auth-modal">
        <div class="modal-header">
          <h5 class="modal-title" id="authModalTitle">Area Utente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-pills cv-auth-tabs mb-3" id="authTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="auth-login-tab" data-bs-toggle="pill" data-bs-target="#auth-login-pane" type="button" role="tab" aria-controls="auth-login-pane" aria-selected="true">Accedi</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="auth-register-tab" data-bs-toggle="pill" data-bs-target="#auth-register-pane" type="button" role="tab" aria-controls="auth-register-pane" aria-selected="false">Registrati</button>
            </li>
          </ul>

          <div class="tab-content" id="authTabsContent">
            <div class="tab-pane fade show active" id="auth-login-pane" role="tabpanel" aria-labelledby="auth-login-tab" tabindex="0">
              <form id="loginForm" novalidate>
                <div class="mb-3">
                  <label for="loginEmail" class="cv-label">Email</label>
                  <input type="email" class="form-control cv-auth-input" id="loginEmail" placeholder="nome@email.it" autocomplete="email">
                  <div class="invalid-feedback">Inserisci una email valida.</div>
                </div>
                <div class="mb-3">
                  <label for="loginPassword" class="cv-label">Password</label>
                  <input type="password" class="form-control cv-auth-input" id="loginPassword" placeholder="Password" autocomplete="current-password">
                  <div class="invalid-feedback">Inserisci la password.</div>
                </div>
                <div class="text-end mb-3">
                  <button type="button" class="btn btn-link p-0 cv-auth-link-btn cv-open-forgot-password">
                    Password dimenticata?
                  </button>
                </div>
                <button type="submit" class="btn cv-modal-primary w-100">Accedi</button>
              </form>

              <div class="cv-auth-divider"><span>oppure</span></div>

              <button type="button" id="googleLoginBtn" class="btn cv-google-btn w-100">
                <i class="bi bi-google me-2"></i>
                Continua con Google
              </button>
              <small class="cv-auth-note">Struttura pronta. Attivazione OAuth nel prossimo step.</small>
            </div>

            <div class="tab-pane fade" id="auth-register-pane" role="tabpanel" aria-labelledby="auth-register-tab" tabindex="0">
              <form id="registerForm" novalidate>
                <div class="mb-3">
                  <label for="registerName" class="cv-label">Nome e cognome</label>
                  <input type="text" class="form-control cv-auth-input" id="registerName" placeholder="Mario Rossi" autocomplete="name">
                  <div class="invalid-feedback">Inserisci nome e cognome.</div>
                </div>
                <div class="mb-3">
                  <label for="registerEmail" class="cv-label">Email</label>
                  <input type="email" class="form-control cv-auth-input" id="registerEmail" placeholder="nome@email.it" autocomplete="email">
                  <div class="invalid-feedback">Inserisci una email valida.</div>
                </div>
                <div class="mb-3">
                  <label for="registerPhone" class="cv-label">Telefono</label>
                  <input type="text" class="form-control cv-auth-input" id="registerPhone" placeholder="+39 333 1234567" autocomplete="tel">
                  <div class="invalid-feedback">Inserisci un numero valido.</div>
                </div>
                <div class="mb-3">
                  <label for="registerProvince" class="cv-label">Provincia di residenza</label>
                  <select class="form-select cv-auth-input cv-province-select" id="registerProvince" data-selected="">
                    <option value="">Seleziona provincia</option>
                  </select>
                  <div class="invalid-feedback">Seleziona la provincia.</div>
                </div>
                <div class="mb-3">
                  <label for="registerPassword" class="cv-label">Password</label>
                  <input type="password" class="form-control cv-auth-input" id="registerPassword" placeholder="Minimo 6 caratteri" autocomplete="new-password">
                  <div class="invalid-feedback">Inserisci almeno 6 caratteri.</div>
                </div>
                <div class="mb-3">
                  <label for="registerPasswordConfirm" class="cv-label">Conferma password</label>
                  <input type="password" class="form-control cv-auth-input" id="registerPasswordConfirm" placeholder="Ripeti password" autocomplete="new-password">
                  <div class="invalid-feedback">Le password devono coincidere.</div>
                </div>
                <div class="mb-3 form-check cv-checkbox">
                  <input class="form-check-input" type="checkbox" id="registerNewsletter" checked>
                  <label class="form-check-label" for="registerNewsletter">Voglio ricevere offerte e aggiornamenti via newsletter</label>
                </div>
                <button type="submit" class="btn cv-modal-primary w-100">Crea account</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content cv-modal">
        <div class="modal-header">
          <h5 class="modal-title" id="forgotPasswordModalTitle">Ripristina password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <form id="forgotPasswordForm" novalidate>
            <p class="cv-modal-hint mb-3">Inserisci email e nuova password. Riceverai un link di conferma via email.</p>
            <div class="mb-3">
              <label for="forgotPasswordEmail" class="cv-label">Email account</label>
              <input type="email" class="form-control cv-auth-input" id="forgotPasswordEmail" placeholder="nome@email.it" autocomplete="email">
              <div class="invalid-feedback">Inserisci una email valida.</div>
            </div>
            <div class="mb-3">
              <label for="forgotPasswordNewPassword" class="cv-label">Nuova password</label>
              <input type="password" class="form-control cv-auth-input" id="forgotPasswordNewPassword" placeholder="Minimo 6 caratteri" autocomplete="new-password">
              <div class="invalid-feedback">Inserisci almeno 6 caratteri.</div>
            </div>
            <div class="mb-3">
              <label for="forgotPasswordConfirmPassword" class="cv-label">Conferma nuova password</label>
              <input type="password" class="form-control cv-auth-input" id="forgotPasswordConfirmPassword" placeholder="Ripeti password" autocomplete="new-password">
              <div class="invalid-feedback">Le password devono coincidere.</div>
            </div>
            <button type="submit" class="btn cv-modal-primary w-100">Invia link di conferma</button>
          </form>
        </div>
      </div>
    </div>
  </div>
HTML;
    }
}

if (!function_exists('cvRenderSiteFooter')) {
    function cvRenderSiteFooter(string $class = 'mt-4'): string
    {
        $baseUrl = cvSiteBaseUrl();
        $year = date('Y');
        $escapedClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return <<<HTML
    <footer class="cv-site-footer {$escapedClass}">
      <span>&copy; {$year} <a href="https://fillbus.it" target="_blank" rel="noopener noreferrer">fillbus.it</a></span>
      <div class="cv-site-footer-links">
        <a href="{$baseUrl}/privacy.php">Privacy Policy</a>
        <a href="{$baseUrl}/cookie.php">Cookie Policy</a>
        <a href="{$baseUrl}/faq.php">FAQ</a>
        <a href="{$baseUrl}/chi-siamo.php">Chi siamo</a>
        <a href="{$baseUrl}/documentazione-endpoint.php">Endpoint / Documentazione</a>
        <a href="{$baseUrl}/partner.php">Diventa partner</a>
        <a href="{$baseUrl}/">Home</a>
      </div>
    </footer>
HTML;
    }
}
