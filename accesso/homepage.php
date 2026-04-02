<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/functions.php';

$state = cvAccessoInit();
if (!$state['authenticated']) {
    cvAccessoRenderLoginPage($state);
    return;
}

$providerCards = [];
$settings = cvRuntimeSettingsDefaults();

try {
    $connection = cvAccessoRequireConnection();
    cvHomepageFeaturedRoutesEnsureTable($connection);
    $settings = cvRuntimeSettings($connection);

    $providers = cvAccessoFilterProviders($state, cvCacheFetchProviders($connection));
    $defaultPerProvider = isset($settings['homepage_popular_per_provider'])
        ? (int) $settings['homepage_popular_per_provider']
        : 4;
    $homePopularProviderCodes = cvRuntimeSettingCsvList($settings['homepage_popular_provider_codes'] ?? '');
    $homePopularProviderLimits = cvRuntimeSettingJsonMap($settings['homepage_popular_provider_limits'] ?? '');

    $selectedProviderSet = [];
    foreach ($homePopularProviderCodes as $providerCode) {
        $providerCode = strtolower(trim((string) $providerCode));
        if ($providerCode !== '') {
            $selectedProviderSet[$providerCode] = true;
        }
    }

    $scopeProviderCodes = [];
    foreach ($providers as $provider) {
        if (!is_array($provider)) {
            continue;
        }
        $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($providerCode !== '') {
            $scopeProviderCodes[] = $providerCode;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_homepage_featured_routes') {
        if (!cvAccessoValidateCsrf()) {
            $state['errors'][] = 'Sessione non valida. Ricarica la pagina.';
        } else {
            $postedRoutes = isset($_POST['featured_routes']) && is_array($_POST['featured_routes'])
                ? $_POST['featured_routes']
                : [];

            foreach ($providers as $provider) {
                if (!is_array($provider)) {
                    continue;
                }

                $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
                $providerName = trim((string) ($provider['name'] ?? $providerCode));
                $providerIsActive = (int) ($provider['is_active'] ?? 0) === 1;
                if ($providerCode === '') {
                    continue;
                }

                $isEnabledByAdmin = count($selectedProviderSet) === 0
                    ? $providerIsActive
                    : isset($selectedProviderSet[$providerCode]);
                $maxRoutes = $homePopularProviderLimits[$providerCode] ?? $defaultPerProvider;
                $maxRoutes = max(0, min(200, (int) $maxRoutes));

                if (!$providerIsActive || !$isEnabledByAdmin || $maxRoutes <= 0) {
                    continue;
                }

                $candidateRoutes = cvHomepageFeaturedRoutesFetchProviderCandidates($connection, $providerCode);
                $allowedRouteKeys = [];
                foreach ($candidateRoutes as $candidateRoute) {
                    if (!is_array($candidateRoute)) {
                        continue;
                    }
                    $routeKey = trim((string) ($candidateRoute['route_key'] ?? ''));
                    if ($routeKey !== '') {
                        $allowedRouteKeys[$routeKey] = true;
                    }
                }

                $providerPostedRoutes = isset($postedRoutes[$providerCode]) && is_array($postedRoutes[$providerCode])
                    ? $postedRoutes[$providerCode]
                    : [];
                $validRouteKeys = [];
                foreach ($providerPostedRoutes as $routeKey) {
                    $routeKey = trim((string) $routeKey);
                    if ($routeKey === '' || !isset($allowedRouteKeys[$routeKey])) {
                        continue;
                    }
                    $validRouteKeys[$routeKey] = $routeKey;
                }

                $routeKeysToSave = array_values($validRouteKeys);
                if (count($routeKeysToSave) > $maxRoutes) {
                    $routeKeysToSave = array_slice($routeKeysToSave, 0, $maxRoutes);
                    $state['errors'][] = 'Per ' . $providerName . ' sono state salvate solo le prime ' . $maxRoutes . ' tratte consentite.';
                }

                cvHomepageFeaturedRoutesReplaceProviderSelections($connection, $providerCode, $routeKeysToSave);
            }

            $state['messages'][] = 'Vetrina home aggiornata.';
        }
    }

    $selectedRoutesMap = cvHomepageFeaturedRoutesFetchSelections($connection, $scopeProviderCodes);

    foreach ($providers as $provider) {
        if (!is_array($provider)) {
            continue;
        }

        $providerCode = strtolower(trim((string) ($provider['code'] ?? '')));
        if ($providerCode === '') {
            continue;
        }

        $providerName = trim((string) ($provider['name'] ?? $providerCode));
        $providerIsActive = (int) ($provider['is_active'] ?? 0) === 1;
        $isEnabledByAdmin = count($selectedProviderSet) === 0
            ? $providerIsActive
            : isset($selectedProviderSet[$providerCode]);
        $maxRoutes = $homePopularProviderLimits[$providerCode] ?? $defaultPerProvider;
        $maxRoutes = max(0, min(200, (int) $maxRoutes));
        $canManage = $providerIsActive && $isEnabledByAdmin && $maxRoutes > 0;

        $candidateRoutes = cvHomepageFeaturedRoutesFetchProviderCandidates($connection, $providerCode);
        $candidateRouteKeyMap = [];
        foreach ($candidateRoutes as $candidateRoute) {
            if (!is_array($candidateRoute)) {
                continue;
            }
            $routeKey = trim((string) ($candidateRoute['route_key'] ?? ''));
            if ($routeKey !== '') {
                $candidateRouteKeyMap[$routeKey] = true;
            }
        }

        $selectedRoutes = isset($selectedRoutesMap[$providerCode]) && is_array($selectedRoutesMap[$providerCode])
            ? $selectedRoutesMap[$providerCode]
            : [];
        $selectedValidRouteKeys = [];
        $invalidSelectedRoutes = [];
        foreach ($selectedRoutes as $selectedRoute) {
            if (!is_array($selectedRoute)) {
                continue;
            }
            $routeKey = trim((string) ($selectedRoute['route_key'] ?? ''));
            $isValid = !empty($selectedRoute['is_valid']) && isset($candidateRouteKeyMap[$routeKey]);
            if ($isValid) {
                $selectedValidRouteKeys[] = $routeKey;
            } else {
                $invalidSelectedRoutes[] = $selectedRoute;
            }
        }

        $providerCards[] = [
            'code' => $providerCode,
            'name' => $providerName,
            'is_active' => $providerIsActive,
            'is_enabled_by_admin' => $isEnabledByAdmin,
            'max_routes' => $maxRoutes,
            'can_manage' => $canManage,
            'candidate_routes' => $candidateRoutes,
            'selected_valid_route_keys' => $selectedValidRouteKeys,
            'invalid_selected_routes' => $invalidSelectedRoutes,
        ];
    }
} catch (Throwable $exception) {
    $state['errors'][] = 'Errore sezione vetrina home: ' . $exception->getMessage();
}

cvAccessoRenderPageStart('Vetrina home', 'homepage-featured', $state);
?>
<div class="row">
    <div class="col-md-12">
        <p class="cv-page-intro">
            In questa sezione ogni provider sceglie manualmente le tratte da mostrare in home nella sezione
            <strong>In evidenza per provider</strong>.
            Il numero massimo per provider resta governato dai limiti impostati dall’amministratore globale.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="cv-panel-card">
            <div class="cv-panel-head">
                <div>
                    <h4>Regola operativa</h4>
                    <div class="cv-muted">
                        La home non genera più tratte arbitrarie per i provider. Mostra solo le tratte che il provider ha selezionato qui, entro il tetto assegnato da admin.
                    </div>
                </div>
            </div>
            <ul class="cv-home-guidelines mb-0">
                <li>Se il provider non seleziona nessuna tratta, in home non compare nulla per quel provider.</li>
                <li>Se il provider è disattivato o il limite admin è 0, la sezione resta bloccata in sola lettura.</li>
                <li>Se una tratta non ha più direct fare valido nei dati sync, viene segnalata e non viene mostrata in home.</li>
            </ul>
        </div>
    </div>
</div>

<form method="post">
    <input type="hidden" name="action" value="save_homepage_featured_routes">
    <?= cvAccessoCsrfField() ?>

    <div class="row">
        <?php if (count($providerCards) === 0): ?>
            <div class="col-md-12">
                <div class="cv-panel-card">
                    <div class="cv-empty">Nessun provider disponibile nel tuo scope.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($providerCards as $providerCard): ?>
            <?php
            $providerCode = (string) ($providerCard['code'] ?? '');
            $providerName = (string) ($providerCard['name'] ?? $providerCode);
            $providerIsActive = !empty($providerCard['is_active']);
            $isEnabledByAdmin = !empty($providerCard['is_enabled_by_admin']);
            $maxRoutes = (int) ($providerCard['max_routes'] ?? 0);
            $canManage = !empty($providerCard['can_manage']);
            $candidateRoutes = is_array($providerCard['candidate_routes'] ?? null) ? $providerCard['candidate_routes'] : [];
            $selectedValidRouteKeys = is_array($providerCard['selected_valid_route_keys'] ?? null) ? $providerCard['selected_valid_route_keys'] : [];
            $invalidSelectedRoutes = is_array($providerCard['invalid_selected_routes'] ?? null) ? $providerCard['invalid_selected_routes'] : [];
            $searchInputId = 'homepage_route_search_' . $providerCode;
            $selectId = 'homepage_routes_' . $providerCode;
            ?>
            <div class="col-md-12 col-lg-6">
                <div class="cv-panel-card cv-home-provider-card">
                    <div class="cv-panel-head">
                        <div>
                            <h4><?= cvAccessoH($providerName) ?></h4>
                            <div class="cv-muted"><?= cvAccessoH($providerCode) ?></div>
                        </div>
                        <div class="cv-home-provider-badges">
                            <?php if ($providerIsActive): ?>
                                <span class="cv-home-badge cv-home-badge-ok">Provider attivo</span>
                            <?php else: ?>
                                <span class="cv-home-badge cv-home-badge-off">Provider inattivo</span>
                            <?php endif; ?>

                            <?php if ($isEnabledByAdmin && $maxRoutes > 0): ?>
                                <span class="cv-home-badge cv-home-badge-info">Max <?= (int) $maxRoutes ?> tratte</span>
                            <?php elseif ($maxRoutes <= 0): ?>
                                <span class="cv-home-badge cv-home-badge-off">Limite admin = 0</span>
                            <?php else: ?>
                                <span class="cv-home-badge cv-home-badge-off">Non abilitato in home</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canManage): ?>
                        <div class="form-group">
                            <label for="<?= cvAccessoH($searchInputId) ?>">Filtra tratte valide</label>
                            <input
                                type="text"
                                id="<?= cvAccessoH($searchInputId) ?>"
                                class="form-control cv-home-search-input"
                                data-target-select="<?= cvAccessoH($selectId) ?>"
                                placeholder="Cerca per partenza o destinazione"
                            >
                            <div class="cv-muted" style="margin-top:8px;">
                                Seleziona solo le tratte che vuoi pubblicare in home per questo provider.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="<?= cvAccessoH($selectId) ?>">
                                Tratte disponibili
                                <span class="cv-home-selection-count" data-for-select="<?= cvAccessoH($selectId) ?>" data-max-routes="<?= (int) $maxRoutes ?>">
                                    <?= count($selectedValidRouteKeys) ?>/<?= (int) $maxRoutes ?> selezionate
                                </span>
                            </label>
                            <select
                                id="<?= cvAccessoH($selectId) ?>"
                                name="featured_routes[<?= cvAccessoH($providerCode) ?>][]"
                                class="form-control cv-home-featured-select"
                                multiple
                                size="12"
                                data-max-routes="<?= (int) $maxRoutes ?>"
                            >
                                <?php foreach ($candidateRoutes as $route): ?>
                                    <?php
                                    $routeKey = (string) ($route['route_key'] ?? '');
                                    $fromName = (string) ($route['from_name'] ?? '');
                                    $toName = (string) ($route['to_name'] ?? '');
                                    $minAmount = isset($route['min_amount']) ? (float) $route['min_amount'] : 0.0;
                                    $label = $fromName . ' -> ' . $toName;
                                    if ($minAmount > 0) {
                                        $label .= ' · da ' . cvFormatEuro($minAmount);
                                    }
                                    ?>
                                    <option
                                        value="<?= cvAccessoH($routeKey) ?>"
                                        <?= in_array($routeKey, $selectedValidRouteKeys, true) ? ' selected' : '' ?>
                                    >
                                        <?= cvAccessoH($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cv-muted" style="margin-top:8px;">
                                Suggerimento: usa il filtro sopra e tieni premuto Cmd/Ctrl per selezionare o deselezionare più tratte.
                            </div>
                        </div>

                        <?php if (count($invalidSelectedRoutes) > 0): ?>
                            <div class="alert alert-warning cv-alert" role="alert">
                                <strong>Attenzione:</strong> alcune tratte salvate in precedenza non risultano più valide con i direct fares attuali e non verranno pubblicate in home.
                                <ul class="cv-home-invalid-list">
                                    <?php foreach ($invalidSelectedRoutes as $invalidRoute): ?>
                                        <li>
                                            <?= cvAccessoH((string) ($invalidRoute['from_name'] ?? '')) ?>
                                            →
                                            <?= cvAccessoH((string) ($invalidRoute['to_name'] ?? '')) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="cv-empty">
                            <?php if (!$providerIsActive): ?>
                                Il provider è inattivo: al momento non può pubblicare tratte in home.
                            <?php elseif (!$isEnabledByAdmin): ?>
                                Questo provider non è attualmente abilitato dall’amministratore nella sezione viaggi popolari della home.
                            <?php else: ?>
                                Il limite impostato dall’amministratore per questo provider è 0, quindi la vetrina home è disattivata.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($providerCards) > 0): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="cv-inline-actions">
                    <button type="submit" class="btn btn-primary">Salva vetrina home</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</form>

<script>
(function () {
    var searchInputs = document.querySelectorAll('.cv-home-search-input');

    function updateCount(selectEl) {
        if (!selectEl) {
            return;
        }

        var countEl = document.querySelector('.cv-home-selection-count[data-for-select="' + selectEl.id + '"]');
        if (!countEl) {
            return;
        }

        var selectedCount = 0;
        Array.prototype.forEach.call(selectEl.options, function (option) {
            if (option.selected) {
                selectedCount += 1;
            }
        });

        var maxRoutes = countEl.getAttribute('data-max-routes') || selectEl.getAttribute('data-max-routes') || '0';
        countEl.textContent = selectedCount + '/' + maxRoutes + ' selezionate';
        if (Number(maxRoutes) > 0 && selectedCount > Number(maxRoutes)) {
            countEl.classList.add('cv-home-selection-count-over');
        } else {
            countEl.classList.remove('cv-home-selection-count-over');
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('.cv-home-featured-select'), function (selectEl) {
        updateCount(selectEl);
        selectEl.addEventListener('change', function () {
            updateCount(selectEl);
        });
    });

    Array.prototype.forEach.call(searchInputs, function (inputEl) {
        inputEl.addEventListener('input', function () {
            var selectId = inputEl.getAttribute('data-target-select') || '';
            var selectEl = document.getElementById(selectId);
            if (!selectEl) {
                return;
            }

            var needle = inputEl.value.trim().toLowerCase();
            Array.prototype.forEach.call(selectEl.options, function (option) {
                if (needle === '') {
                    option.hidden = false;
                    return;
                }

                option.hidden = option.text.toLowerCase().indexOf(needle) === -1;
            });
        });
    });
})();
</script>
<?php
cvAccessoRenderPageEnd();
