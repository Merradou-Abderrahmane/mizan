<?php

namespace Database\Seeders;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Level;
use App\Models\Referentiel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic référentiel graph for the smoke-test harness:
 *   1 Référentiel (CDA 2023)
 *   3 Levels (N1 imiter / N2 adapter / N3 transposer)
 *  11 Competences (5 kind=technique + 6 kind=transversale)
 *  33 Criteria (one per (competence, level) pair)
 *   1 Brief (ThreadForge API)
 *   5 brief_competence pivot rows (one per technique competence, with target level_id)
 *
 * Idempotent: every row is built via firstOrCreate keyed on stable matching
 * attributes (or syncWithoutDetaching for the pivot keyed on
 * (brief_id, competence_id)), so migrate:fresh --seed and any re-run is
 * repeatable without constraint violations or duplicate rows (R5).
 *
 * R4 — persona kept out: the seeder does NOT seed any StudentRepo and does NOT
 * set operator_persona on any row. Identity stays operator-private and only
 * enters the system at runtime via `php artisan repo:intake`.
 *
 * NOTE — SMOKE TEST FIXTURES, NOT THE AUTHORITATIVE RÉFÉRENTIEL:
 * the criterion texts below are inspired by the ThreadForge brief's
 * "Critères de performance" section; they make the live glm-5.2 grade
 * meaningful as a plumbing proof, not as a real evaluation. The operator MUST
 * replace these with the real critères d'évaluation from the authoritative
 * référentiel before any real evaluation.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $referentiel = $this->seedReferentiel();
        $levels = $this->seedLevels($referentiel);
        $competences = $this->seedCompetences($referentiel);
        $this->seedCriteria($competences, $levels);
        $brief = $this->seedBrief($referentiel);
        $this->seedBriefCompetencePivot($brief, $competences, $levels);
    }

    private function seedReferentiel(): Referentiel
    {
        return Referentiel::firstOrCreate(
            ['title' => 'CDA 2023 — Concepteur⋅rice développeur⋅se d\'applications'],
            [
                'description' => 'Référentiel de certification CDA 2023. Niveaux progressifs : imiter (N1), adapter (N2), transposer (N3).',
            ],
        );
    }

    /**
     * @return array<string, Level> keyed by Level.code (N1/N2/N3)
     */
    private function seedLevels(Referentiel $referentiel): array
    {
        $rows = [
            ['code' => 'N1', 'label' => 'imiter', 'sort_order' => 1],
            ['code' => 'N2', 'label' => 'adapter', 'sort_order' => 2],
            ['code' => 'N3', 'label' => 'transposer', 'sort_order' => 3],
        ];

        $levels = [];
        foreach ($rows as $row) {
            $levels[$row['code']] = Level::firstOrCreate(
                ['referentiel_id' => $referentiel->id, 'code' => $row['code']],
                ['label' => $row['label'], 'sort_order' => $row['sort_order']],
            );
        }

        return $levels;
    }

    /**
     * @return array<string, Competence> keyed by Competence.code
     */
    private function seedCompetences(Referentiel $referentiel): array
    {
        // R5: explicit competency list keyed on stable codes (per the change spec).
        $rows = [
            // 5 kind=technique (Pass 1 grades these)
            ['code' => 'T-C5', 'label' => 'Développer la partie back-end d\'une interface utilisateur web', 'kind' => 'technique'],
            ['code' => 'T-C6', 'label' => 'Développer des composants d\'accès aux données',         'kind' => 'technique'],
            ['code' => 'T-C3', 'label' => 'Développer des composants métier',                       'kind' => 'technique'],
            ['code' => 'T-C7', 'label' => 'Concevoir et mettre en place une base de données',        'kind' => 'technique'],
            ['code' => 'T-C9', 'label' => 'Préparer et exécuter les plans de tests',                 'kind' => 'technique'],
            // 6 kind=transversale (operator-validated only; never in Pass 1)
            ['code' => 'TR-C1',  'label' => 'Planifier le travail à effectuer individuellement',                       'kind' => 'transversale'],
            ['code' => 'TR-C2',  'label' => 'Contribuer au pilotage de l\'organisation du travail individuel et collectif', 'kind' => 'transversale'],
            ['code' => 'TR-C3',  'label' => 'Définir le périmètre d\'un problème rencontré en adoptant une démarche inductive', 'kind' => 'transversale'],
            ['code' => 'TR-C4',  'label' => 'Rechercher de façon méthodique une ou des solutions au problème rencontré', 'kind' => 'transversale'],
            ['code' => 'TR-C5',  'label' => 'Partager la solution adoptée en utilisant les moyens de partage de connaissance ou de documentation disponibles', 'kind' => 'transversale'],
            ['code' => 'TR-C1b', 'label' => 'Installer et configurer son environnement de travail en fonction du projet', 'kind' => 'transversale'],
        ];

        $competences = [];
        foreach ($rows as $row) {
            $competences[$row['code']] = Competence::firstOrCreate(
                ['referentiel_id' => $referentiel->id, 'code' => $row['code']],
                ['label' => $row['label'], 'kind' => $row['kind']],
            );
        }

        return $competences;
    }

    /**
     * @param array<string, Competence> $competences
     * @param array<string, Level>      $levels
     */
    private function seedCriteria(array $competences, array $levels): void
    {
        $criteriaMatrix = self::criteriaMatrix();

        foreach ($competences as $competenceCode => $competence) {
            // Transversale competences: still get criteria per level (operator
            // may want to validate at a specific level later); the criteria
            // are generic for transversales since they are operator-validated.
            foreach ($levels as $levelCode => $level) {
                $cCode = "{$competenceCode}-{$levelCode}";
                $description = $criteriaMatrix[$competenceCode][$levelCode]
                    ?? "Critère d'évaluation transversal — {$level->label} le comportement {$competence->label} à ce niveau.";

                Criterion::firstOrCreate(
                    ['competence_id' => $competence->id, 'level_id' => $level->id, 'code' => $cCode],
                    [
                        'label'       => "{$competence->label} — {$level->label}",
                        'description' => $description,
                        'sort_order'  => $level->sort_order,
                    ],
                );
            }
        }
    }

    private function seedBrief(Referentiel $referentiel): Brief
    {
        return Brief::firstOrCreate(
            [
                'referentiel_id' => $referentiel->id,
                'title'          => 'ThreadForge API — Assistant IA de Repurposing et Distribution pour Créateurs Tech',
            ],
            [
                'description' => self::briefDescription(),
                'payload'     => null,
            ],
        );
    }

    /**
     * Attach the five technique competences to the brief at their target level
     * (Change spec, table). No transversale competence is attached — Pass 1
     * grades technique only.
     *
     * @param array<string, Competence> $competences
     * @param array<string, Level>      $levels
     */
    private function seedBriefCompetencePivot(Brief $brief, array $competences, array $levels): void
    {
        $targets = self::targetLevels();

        $sync = [];
        foreach ($targets as $competenceCode => $levelCode) {
            $sync[$competences[$competenceCode]->id] = ['level_id' => $levels[$levelCode]->id];
        }

        $brief->competences()->syncWithoutDetaching($sync);
    }

    /**
     * Target level per technique competence (Change spec, table).
     *
     * @return array<string, string> competenceCode => levelCode
     */
    public static function targetLevels(): array
    {
        return [
            'T-C7' => 'N2',
            'T-C6' => 'N2',
            'T-C3' => 'N2',
            'T-C5' => 'N3',
            'T-C9' => 'N1',
        ];
    }

    /**
     * ThreadForge brief text (verbatim from the operator-supplied brief,
     * stored here so the seeder stays self-contained).
     */
    public static function briefDescription(): string
    {
        return <<<'TXT'
ThreadForge API est une solution backend headless (pure API REST) développée avec Laravel. Elle permet aux créateurs de contenu tech de transformer automatiquement des notes techniques brutes, des articles de blog ou des Readme GitHub en posts optimisés et calibrés pour X (Twitter). L'application sépare le style de l'écriture grâce à des configurations réutilisables (Blueprints) et intègre un agent conversationnel doté d'outils et de mémoire pour affiner le contenu généré à la demande.

USER STORIES:
US1 – Inscription / Connexion / Déconnexion via Bearer Token (Laravel Sanctum).
US2 – Créer un Campaign Blueprint (règles de style strictes : ton, hashtags, 280 caractères max).
US3 – Liste et Détail des Blueprints (+ nombre de posts générés).
US4 – Soumettre un contenu brut (Raw Content) via requête API.
US5 – Structured Output de la Génération (hook, body_points, technicalreadabilityscore, suggested_hashtags, tonecompliancejustification).
US6 – Gestion du Cycle de Vie (statuts draft/archived/posted).
US7 – Chat contextuel sur un post généré.
US8 – Memory State (mémoire de conversation sans renvoyer tout le contenu).
US9 – Function Calling via Tools PHP (getCampaignRules, getPostHistory).

CONTRAINTES TECHNIQUES:
- Pure RESTful API (routes/api.php, JSON via API Resources, jamais de Blade).
- Laravel Sanctum (auth:sanctum, Bearer Tokens, 401 sur token invalide).
- Jobs & Queues (202 Accepted immédiat, traitement async via Job).
- Eloquent Casts (colonnes JSON castées en tableaux PHP natifs).
- Form Requests (422 Unprocessable Entity, aucune erreur SQL brut).
- SDK laravel/ai : Structured Output JSON strict + Agent avec Tools mémoire.
- Outils PHP réels (zéro hallucination sur les données internes).
- Continuité contextuelle via les tables de persistance des conversations.

PERFORMANCE:
1. Architecture API & Sécurité (35%) — zéro fuite de données, routes stateless, Form Requests robustes.
2. Intégration IA & Asynchronisme (30%) — 202 < 100ms, contrat JSON strict, casts Eloquent natifs.
3. Couche Agentic (20%) — zéro hallucination (tools), continuité contextuelle (mémoire).
4. Qualité du Code (15%) — eager loading (zéro N+1), commits atomiques (>=20), Scribe docs.

MODALITÉS: Individuel, 5 jours, livraison Vendredi 26/06/2026 16:30.
TXT;
    }

    /**
     * Criterion description matrix — one description per (competence, level).
     * Empty entries fall back to a generic transversale description.
     *
     * Inspired by the ThreadForge brief's "Critères de performance" families:
     * Sanctum / API Resources / N+1 / Queue / 202 Accepted / structured
     * output / Eloquent JSON cast / function-calling tools / conversation
     * memory / atomic commits / Scribe.
     *
     * @return array<string, array<string, string>> competenceCode => levelCode => description
     */
    public static function criteriaMatrix(): array
    {
        return [
            // T-C7 — Concevoir et mettre en place une base de données
            'T-C7' => [
                'N1' => 'Le schéma contient toutes les entités du brief (users, campaigns, posts, conversations, messages) sous forme de migrations Laravel, avec clés étrangères basiques.',
                'N2' => 'Le schéma respecte le MCD : les colonnes JSON (body_points, suggested_hashtags) sont typées `json` et contraintes d\'intégrité sont posées (cascades, restrict). Aucune migration n\'écrase un alias Laravel réservé.',
                'N3' => 'Le schéma est conçu pour l\'asynchrone (une table de file dédiée ou `jobs` Laravel) et isole les payload d\'IA en colonnes JSON castées nativement, sans json_encode manuel dans le code applicatif.',
            ],
            // T-C6 — Développer des composants d'accès aux données
            'T-C6' => [
                'N1' => 'Des modèles Eloquent existent pour chaque entité du schéma, avec `$fillable` positionné et les relations `belongsTo`/`hasMany` de base.',
                'N2' => 'Les accès aux données Eloquent utilisent systématiquement l\'eager loading (`with()`) afin d\'éviter les N+1 dans les listes (posts liés à leur campagne et à leur raw content). Les colonnes JSON (body_points, suggested_hashtags) sont manipulées comme tableaux PHP natifs via `$casts`.',
                'N3' => 'Les composants d\'accès aux données exposent une abstraction de repository ou de scope réutilisable pour les critères de style (ton, hashtags, lisibilité) sans fuite de logique métier dans les contrôleurs.',
            ],
            // T-C3 — Développer des composants métier
            'T-C3' => [
                'N1' => 'Des Jobs Laravel existent pour le traitement asynchrone de l\'IA et sont dispatchés depuis les contrôleurs API (`dispatch(new ...Job)`).',
                'N2' => 'Le Job vérifie le contrat JSON strict (hook_propose, body_points, technicalreadabilityscore, suggested_hashtags, tonecompliancejustification) avant l\'insertion en base. Les exceptions de parsing du contrat lèvent un blocage `failed` du Job.',
                'N3' => 'Les composants métier orchestrent le chainage Tools (getCampaignRules(), getPostHistory()) de l\'agent laravel/ai avec mémoire de conversation persistée à chaque étape.',
            ],
            // T-C5 — Développer la partie back-end d'une interface web
            'T-C5' => [
                'N1' => 'Les routes sont exclusivement sous `routes/api.php` (aucun contrôleur ne retourne Blade). Laravel Sanctum protège les routes via `auth:sanctum`.',
                'N2' => 'Les réponses JSON passent systématiquement par des Laravel API Resources (filtrage des mots de passe hachés, des dates brutes et des clés internes). Les Form Requests renvoient du 422 JSON propre sur erreur de validation.',
                'N3' => 'L\'architecture est headless complète : l\'endpoint `POST /api/content/repurpose` répond en moins de 100ms avec `202 Accepted` via un Job asynchrone; aucune logique bloquante n\'apparaît dans le contrôleur HTTP.',
            ],
            // T-C9 — Préparer et exécuter les plans de tests
            'T-C9' => [
                'N1' => 'Un fichier de test PHPUnit existe et un `README.md` indique comment exécuter la suite. Des Feature tests couvrent au minimum une route d\'authentification et une route de génération.',
                'N2' => 'Les tests couvrent les cas nominaux ET les erreurs (401 sans token, 422 sur payload invalide, 202 suivi du traitement async). Les tests utilisent `RefreshDatabase` et casts natifs.',
                'N3' => 'Les tests couvrent le contrat Structured Output (échec du Job si une clé JSON manque) et les Tools d\'agent (vérification qu\'un Tool est réellement appelé et non halluciné), avec au moins un test d\'intégration asynchrone.',
            ],
            // T-C5 (transversale) — Partager la solution adoptée
            'TR-C5' => [
                'N1' => 'Le README.md du repo contient une section "Installation" (composer install, php artisan migrate, php artisan serve).',
                'N2' => 'Le README.md documente le contrat de l\'API et les endpoints au moins via Scribe ou des exemples curl.',
                'N3' => 'La documentation via Scribe est complète et illustre chaque endpoint avec exemples de requêtes et réponses pré-remplies.',
            ],
        ];
    }
}