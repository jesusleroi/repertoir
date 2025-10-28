<?php
// comptabilite_tresorerie.php - Gestion de la comptabilité et trésorerie

// Enregistrement léger côté serveur (JSON dans ../data/operations.json) pour la modale simple
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['date_op'], $_POST['type_op'], $_POST['libelle'], $_POST['montant'])) {
    header('Content-Type: application/json; charset=utf-8');

    $date = trim($_POST['date_op']);
    $type = $_POST['type_op'] === 'sortie' ? 'sortie' : 'entree';
    $libelle = trim($_POST['libelle']);
    $montant = floatval($_POST['montant']);
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $numero_piece = isset($_POST['numero_piece']) ? trim($_POST['numero_piece']) : '';

    if ($date === '' || $libelle === '' || $montant <= 0) {
        echo json_encode(['success' => false, 'message' => "Champs requis manquants ou montant invalide."]);
        exit;
    }

    $file = dirname(__DIR__) . '/data/operations.json';
    $ops = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $ops = $decoded;
        }
    }

    $op = [
        'id' => uniqid('op_', true),
        'date_operation' => $date,
        'type_operation' => $type,
        'libelle' => $libelle,
        'montant' => $montant,
        'numero_piece' => $numero_piece,
        'notes' => $notes,
        'created_at' => date('Y-m-d H:i:s')
    ];
    $ops[] = $op;

    if (file_put_contents($file, json_encode($ops, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo json_encode(['success' => true, 'operation' => $op]);
    } else {
        echo json_encode(['success' => false, 'message' => "Impossible d'enregistrer l'opération."]);
    }
    exit;
}

session_start();
require_once 'connexion_bdd.php';
// Configuration de la devise guinéenne
define('DEVISE_CODE', 'FG');
define('DEVISE_NOM', 'Franc Guinéen');
define('DEVISE_SYMBOLE', 'FG');
define('DEVISE_POSITION', 'apres'); // 'avant' ou 'apres'
define('DEVISE_SEP_MILLIERS', ' ');
define('DEVISE_SEP_DECIMALES', ',');
define('DEVISE_NB_DECIMALES', 2);

/**
 * Formate un montant en Francs Guinéens (FG)
 */
function formatMontantFG($montant, $avecDevise = true) {
    if ($montant === null || $montant === '' || !is_numeric($montant)) {
        return $avecDevise ? '0 FG' : '0';
    }
    
    $montantNum = floatval($montant);
    $formatte = number_format($montantNum, 2, ',', ' ');
    
    return $avecDevise ? $formatte . ' FG' : $formatte;
}

/**
 * Formate un montant compact pour les tableaux de bord
 */
function formatMontantCompactFG($montant) {
    if ($montant === null || $montant === '' || !is_numeric($montant)) {
        return '0 FG';
    }
    
    $montantNum = abs(floatval($montant));
    
    if ($montantNum >= 1000000000) {
        return number_format($montantNum / 1000000000, 1, ',', ' ') . 'B FG';
    } elseif ($montantNum >= 1000000) {
        return number_format($montantNum / 1000000, 1, ',', ' ') . 'M FG';
    } elseif ($montantNum >= 1000) {
        return number_format($montantNum / 1000, 1, ',', ' ') . 'K FG';
    }
    
    return number_format($montantNum, 2, ',', ' ') . ' FG';
}

// Vérifier si l'utilisateur est connecté et a les droits admin
if (!isset($_SESSION['utilisateur_connecte']) || 
    ($_SESSION['utilisateur_connecte']['role_u'] !== 'super_admin' && 
     !in_array($_SESSION['utilisateur_connecte']['fonction_u'], ['Directeur', 'Directeur Adjoint', 'DE', 'Comptable']))) {
    header('Location: connexion.php');
    exit();
}

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=$host;dbname=" . $_SESSION['base_de_donnees'] . ";charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

$message_succes = '';
$message_erreur = '';

// Endpoints GET (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'get_tresorerie') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => "ID invalide"]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id, compte_id, date_operation, libelle, montant, type_operation, moyen_paiement_id, numero_piece, beneficiaire, categorie FROM tresorerie WHERE id = ?");
            $stmt->execute([$id]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($operation) {
                echo json_encode(['success' => true, 'operation' => $operation]);
            } else {
                echo json_encode(['success' => false, 'message' => "Opération introuvable"]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => "Erreur: " . $e->getMessage()]);
        }
        exit;
    }
}

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'ajouter_ecriture':
                    // Validation des données
                    if (empty($_POST['libelle']) || empty($_POST['montant']) || empty($_POST['compte_debit']) || empty($_POST['compte_credit'])) {
                        throw new Exception("Tous les champs obligatoires doivent être remplis");
                    }
                    
                    $montant = floatval($_POST['montant']);
                    if ($montant <= 0) {
                        throw new Exception("Le montant doit être positif");
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Générer un numéro de pièce automatique
                    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(numero_piece, 4) AS UNSIGNED)) as max_num FROM ecritures_comptables WHERE numero_piece LIKE 'EC-%'");
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $next_num = ($result['max_num'] ?? 0) + 1;
                    $numero_piece = 'EC-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
                    
                    // Créer l'écriture principale
                    $stmt = $pdo->prepare("
                        INSERT INTO ecritures_comptables 
                        (numero_piece, date_ecriture, libelle, montant_total, type_operation, utilisateur, observations) 
                        VALUES (?, ?, ?, ?, 'ecriture_comptable', ?, ?)
                    ");
                    $stmt->execute([
                        $numero_piece,
                        $_POST['date_ecriture'],
                        $_POST['libelle'],
                        $montant,
                        $_SESSION['user_nom'],
                        $_POST['observations'] ?? ''
                    ]);
                    
                    $ecriture_id = $pdo->lastInsertId();
                    
                    // Ligne de débit
                    $stmt = $pdo->prepare("
                        INSERT INTO lignes_ecritures (ecriture_id, compte_id, libelle, debit, credit) 
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([$ecriture_id, $_POST['compte_debit'], $_POST['libelle'], $montant]);
                    
                    // Ligne de crédit
                    $stmt = $pdo->prepare("
                        INSERT INTO lignes_ecritures (ecriture_id, compte_id, libelle, debit, credit) 
                        VALUES (?, ?, ?, 0, ?)
                    ");
                    $stmt->execute([$ecriture_id, $_POST['compte_credit'], $_POST['libelle'], $montant]);
                    
                    $pdo->commit();
                    
                    // Recalculer les budgets après validation
                    try {
                        $pdo->exec("CALL CalculerRealisationsBudgets()");
                    } catch (Exception $e) {
                        // Si la procédure n'existe pas encore, on ignore l'erreur
                    }
                    
                    $message_succes = "Écriture comptable ajoutée avec succès !";
                    break;
                    
                case 'ajouter_tresorerie':
                    $montant = floatval($_POST['montant'] ?? 0);
                    if ($montant <= 0) {
                        throw new Exception("Le montant doit être positif");
                    }

                    // Type d'opération (par défaut 'entree')
                    $type_operation = $_POST['type_operation'] ?? 'entree';
                    if (!in_array($type_operation, ['entree', 'sortie'], true)) {
                        $type_operation = 'entree';
                    }

                    // Nouveaux champs: compte_debit et compte_credit (exigés pour la saisie)
                    $compte_debit = !empty($_POST['compte_debit']) ? (int)$_POST['compte_debit'] : null;
                    $compte_credit = !empty($_POST['compte_credit']) ? (int)$_POST['compte_credit'] : null;

                    if (!$compte_debit || !$compte_credit) {
                        throw new Exception("Veuillez sélectionner un compte débité et un compte crédité.");
                    }

                    // Identifier le compte de trésorerie (classe 5*) à utiliser pour la table tresorerie.compte_id
                    $tres_compte_id = null;
                    try {
                        $stmt = $pdo->prepare("SELECT id, numero_compte FROM comptes_comptables WHERE id IN (?, ?)");
                        $stmt->execute([$compte_debit, $compte_credit]);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            if (isset($r['numero_compte']) && preg_match('/^5[0-9]{5}$/', $r['numero_compte'])) {
                                $tres_compte_id = (int)$r['id'];
                                break;
                            }
                        }
                    } catch (Exception $e) {
                        // ignore et laissera les fallbacks ci-dessous
                    }

                    // Fallback si non trouvé: priorité 530000 Caisse, 512000 Banque, sinon tout 5xxxx, sinon compte_debit
                    if (empty($tres_compte_id)) {
                        $stmt = $pdo->query("SELECT id FROM comptes_comptables WHERE numero_compte IN ('530000','512000') ORDER BY FIELD(numero_compte,'530000','512000') LIMIT 1");
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['id'])) {
                            $tres_compte_id = (int)$row['id'];
                        } else {
                            $stmt = $pdo->query("SELECT id FROM comptes_comptables WHERE numero_compte LIKE '5%' ORDER BY numero_compte LIMIT 1");
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $tres_compte_id = isset($row['id']) ? (int)$row['id'] : $compte_debit;
                        }
                    }

                    // Déterminer un moyen de paiement par défaut si non fourni (Espèces sinon premier)
                    $moyen_paiement_id = $_POST['moyen_paiement_id'] ?? null;
                    if (empty($moyen_paiement_id)) {
                        $stmt = $pdo->prepare("SELECT id FROM moyens_paiement WHERE nom = ? LIMIT 1");
                        $stmt->execute(['Espèces']);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['id'])) {
                            $moyen_paiement_id = $row['id'];
                        } else {
                            $stmt = $pdo->query("SELECT id FROM moyens_paiement ORDER BY id LIMIT 1");
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $moyen_paiement_id = $row['id'] ?? null;
                        }
                    }

                    // Catégorie par défaut
                    $categorie = $_POST['categorie'] ?? 'autre';

                    // Enregistrement dans la table tresorerie (conserve un seul compte côté trésorerie pour les soldes Banque/Caisse)
                    $stmt = $pdo->prepare("
                        INSERT INTO tresorerie 
                        (compte_id, date_operation, libelle, montant, type_operation, moyen_paiement_id, 
                         numero_piece, beneficiaire, categorie, utilisateur) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $tres_compte_id,
                        $_POST['date_operation'],
                        $_POST['libelle'],
                        $montant,
                        $type_operation,
                        $moyen_paiement_id,
                        $_POST['numero_piece'] ?? '',
                        $_POST['beneficiaire'] ?? '',
                        $categorie,
                        $_SESSION['user_nom']
                    ]);

                    $message_succes = "Opération de trésorerie ajoutée avec succès !";
                    break;
                    
                case 'valider_ecriture':
                    $stmt = $pdo->prepare("
                        UPDATE ecritures_comptables 
                        SET statut = 'validee', date_validation = NOW(), validee_par = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$_SESSION['user_nom'], $_POST['ecriture_id']]);
                    
                    // Recalculer les budgets après validation
                    try {
                        $pdo->exec("CALL CalculerRealisationsBudgets()");
                    } catch (Exception $e) {
                        // Si la procédure n'existe pas encore, on ignore l'erreur
                    }
                    
                    $message_succes = "Écriture validée avec succès !";
                    break;
                    
                case 'ajouter_budget':
                    $stmt = $pdo->prepare("
                        INSERT INTO budgets (annee_scolaire, compte_id, categorie_id, montant_prevu, trimestre, commentaires, statut) 
                        VALUES (?, ?, ?, ?, ?, ?, 'previsionnel')
                        ON DUPLICATE KEY UPDATE 
                        montant_prevu = VALUES(montant_prevu), 
                        categorie_id = VALUES(categorie_id),
                        commentaires = VALUES(commentaires)
                    ");
                    $stmt->execute([
                        $_POST['annee_scolaire'],
                        $_POST['compte_id'],
                        $_POST['categorie_id'] ?: null,
                        floatval($_POST['montant_prevu']),
                        $_POST['trimestre'],
                        $_POST['commentaires'] ?? ''
                    ]);
                    $message_succes = "Budget mis à jour avec succès !";
                    break;
                    
                case 'valider_budget':
                    $stmt = $pdo->prepare("
                        UPDATE budgets 
                        SET statut = 'valide' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['budget_id']]);
                    $message_succes = "Budget validé avec succès !";
                    break;
                    
                case 'supprimer_ecriture':
                    if (empty($_POST['ecriture_id'])) {
                        throw new Exception("ID de l'écriture manquant");
                    }
                    
                    // Vérifier que l'écriture existe et est en brouillon
                    $stmt = $pdo->prepare("SELECT statut FROM ecritures_comptables WHERE id = ?");
                    $stmt->execute([$_POST['ecriture_id']]);
                    $ecriture = $stmt->fetch();
                    
                    if (!$ecriture) {
                        throw new Exception("Écriture introuvable");
                    }
                    
                    if ($ecriture['statut'] !== 'brouillon') {
                        throw new Exception("Seules les écritures en brouillon peuvent être supprimées");
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Supprimer d'abord les lignes d'écriture
                    $stmt = $pdo->prepare("DELETE FROM lignes_ecritures WHERE ecriture_id = ?");
                    $stmt->execute([$_POST['ecriture_id']]);
                    
                    // Puis supprimer l'écriture principale
                    $stmt = $pdo->prepare("DELETE FROM ecritures_comptables WHERE id = ?");
                    $stmt->execute([$_POST['ecriture_id']]);
                    
                    $pdo->commit();
                    $message_succes = "Écriture supprimée avec succès !";
                    break;
                    
                case 'modifier_ecriture':
                    if (empty($_POST['ecriture_id']) || empty($_POST['libelle']) || empty($_POST['montant'])) {
                        throw new Exception("Données manquantes pour la modification");
                    }
                    
                    // Vérifier que l'écriture existe et est en brouillon
                    $stmt = $pdo->prepare("SELECT statut FROM ecritures_comptables WHERE id = ?");
                    $stmt->execute([$_POST['ecriture_id']]);
                    $ecriture = $stmt->fetch();
                    
                    if (!$ecriture) {
                        throw new Exception("Écriture introuvable");
                    }
                    
                    if ($ecriture['statut'] !== 'brouillon') {
                        throw new Exception("Seules les écritures en brouillon peuvent être modifiées");
                    }
                    
                    $montant = floatval($_POST['montant']);
                    if ($montant <= 0) {
                        throw new Exception("Le montant doit être positif");
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Mettre à jour l'écriture principale
                    $stmt = $pdo->prepare("
                        UPDATE ecritures_comptables 
                        SET libelle = ?, montant_total = ?, observations = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['libelle'],
                        $montant,
                        $_POST['observations'] ?? '',
                        $_POST['ecriture_id']
                    ]);
                    
                    // Supprimer les anciennes lignes
                    $stmt = $pdo->prepare("DELETE FROM lignes_ecritures WHERE ecriture_id = ?");
                    $stmt->execute([$_POST['ecriture_id']]);
                    
                    // Recréer les lignes d'écriture
                    if (!empty($_POST['compte_debit']) && !empty($_POST['compte_credit'])) {
                        // Ligne débit
                        $stmt = $pdo->prepare("
                            INSERT INTO lignes_ecritures (ecriture_id, compte_id, libelle, debit, credit) 
                            VALUES (?, ?, ?, ?, 0)
                        ");
                        $stmt->execute([$_POST['ecriture_id'], $_POST['compte_debit'], $_POST['libelle'], $montant]);
                        
                        // Ligne crédit
                        $stmt = $pdo->prepare("
                            INSERT INTO lignes_ecritures (ecriture_id, compte_id, libelle, debit, credit) 
                            VALUES (?, ?, ?, 0, ?)
                        ");
                        $stmt->execute([$_POST['ecriture_id'], $_POST['compte_credit'], $_POST['libelle'], $montant]);
                    }
                    
                    $pdo->commit();
                    $message_succes = "Écriture modifiée avec succès !";
                    break;
                    
                case 'modifier_tresorerie':
                    if (empty($_POST['tresorerie_id']) || empty($_POST['libelle']) || empty($_POST['montant'])) {
                        throw new Exception("Données manquantes pour la modification");
                    }
                    
                    $montant = floatval($_POST['montant']);
                    if ($montant <= 0) {
                        throw new Exception("Le montant doit être positif");
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE tresorerie 
                        SET date_operation = ?, type_operation = ?, libelle = ?, montant = ?, 
                            compte_id = ?, moyen_paiement_id = ?, beneficiaire = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['date_operation'],
                        $_POST['type_operation'],
                        $_POST['libelle'],
                        $montant,
                        $_POST['compte_id'],
                        $_POST['moyen_paiement_id'],
                        $_POST['beneficiaire'] ?? null,
                        $_POST['tresorerie_id']
                    ]);
                    
                    $message_succes = "Opération de trésorerie modifiée avec succès !";
                    break;
                    
                case 'supprimer_tresorerie':
                    if (empty($_POST['tresorerie_id'])) {
                        throw new Exception("ID de l'opération manquant");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM tresorerie WHERE id = ?");
                    $stmt->execute([$_POST['tresorerie_id']]);
                    
                    $message_succes = "Opération de trésorerie supprimée avec succès !";
                    break;
                    
                case 'ajouter_compte':
                    if (empty($_POST['numero_compte']) || empty($_POST['nom_compte']) || empty($_POST['type_compte'])) {
                        throw new Exception("Tous les champs obligatoires doivent être remplis");
                    }
                    
                    // Vérifier que le numéro de compte n'existe pas déjà
                    $stmt = $pdo->prepare("SELECT id FROM comptes_comptables WHERE numero_compte = ?");
                    $stmt->execute([$_POST['numero_compte']]);
                    if ($stmt->fetch()) {
                        throw new Exception("Ce numéro de compte existe déjà");
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO comptes_comptables (numero_compte, nom_compte, type_compte, categorie) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $_POST['numero_compte'],
                        $_POST['nom_compte'],
                        $_POST['type_compte'],
                        $_POST['categorie']
                    ]);
                    
                    $message_succes = "Compte comptable créé avec succès !";
                    break;
                    
                case 'supprimer_compte':
                    if (empty($_POST['compte_id'])) {
                        throw new Exception("ID du compte manquant");
                    }
                    
                    // Vérifier que le compte n'a pas de mouvements
                    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM lignes_ecritures WHERE compte_id = ?");
                    $stmt->execute([$_POST['compte_id']]);
                    $result = $stmt->fetch();
                    
                    if ($result['nb'] > 0) {
                        throw new Exception("Impossible de supprimer ce compte : il contient des mouvements comptables");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM comptes_comptables WHERE id = ?");
                    $stmt->execute([$_POST['compte_id']]);
                    
                    $message_succes = "Compte comptable supprimé avec succès !";
                    break;
                    
                case 'supprimer_budget':
                    if (empty($_POST['budget_id'])) {
                        throw new Exception("ID du budget manquant");
                    }
                    
                    // Vérifier que le budget est en brouillon
                    $stmt = $pdo->prepare("SELECT statut FROM budgets WHERE id = ?");
                    $stmt->execute([$_POST['budget_id']]);
                    $budget = $stmt->fetch();
                    
                    if (!$budget) {
                        throw new Exception("Budget introuvable");
                    }
                    
                    if ($budget['statut'] !== 'previsionnel') {
                        throw new Exception("Seuls les budgets prévisionnels peuvent être supprimés");
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Supprimer les lignes de budget associées
                    try {
                        $stmt = $pdo->prepare("DELETE FROM lignes_budget WHERE budget_id = ?");
                        $stmt->execute([$_POST['budget_id']]);
                    } catch (Exception $e) {
                        // Table lignes_budget peut ne pas exister
                    }
                    
                    // Supprimer le budget
                    $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ?");
                    $stmt->execute([$_POST['budget_id']]);
                    
                    $pdo->commit();
                    $message_succes = "Budget supprimé avec succès !";
                    break;
                    
                case 'ajouter_categorie_budget':
                    $stmt = $pdo->prepare("
                        INSERT INTO categories_budget (nom_categorie, couleur_interface, icone, description) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['nom_categorie'],
                        $_POST['couleur_interface'],
                        $_POST['icone'],
                        $_POST['description'] ?? ''
                    ]);
                    $message_succes = "Catégorie de budget créée avec succès !";
                    break;
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message_erreur = "Erreur : " . $e->getMessage();
    }
}

// Récupération des données pour les tableaux de bord
$annee_courante = '2024-2025';

// Soldes des comptes principaux
// Correction: intégrer les mouvements de la table tresorerie pour que les soldes Banque (512000) et Caisse (530000) se mettent à jour même
// lorsque l'on saisit une opération simple de trésorerie.
$stmt = $pdo->prepare("
    SELECT 
        c.nom_compte, 
        c.numero_compte, 
        c.type_compte,
        COALESCE(SUM(CASE 
            WHEN c.type_compte IN ('actif', 'charge') THEN l.debit - l.credit 
            ELSE l.credit - l.debit 
        END), 0)
        + COALESCE(tt.net_tresorerie, 0) AS solde
    FROM comptes_comptables c
    LEFT JOIN (
        SELECT 
            compte_id, 
            SUM(CASE WHEN type_operation = 'entree' THEN montant ELSE -montant END) AS net_tresorerie
        FROM tresorerie
        GROUP BY compte_id
    ) tt ON tt.compte_id = c.id
    LEFT JOIN lignes_ecritures l ON c.id = l.compte_id
    LEFT JOIN ecritures_comptables e ON l.ecriture_id = e.id AND e.statut = 'validee'
    WHERE c.numero_compte IN ('512000', '530000', '706000', '641000', '606000')
    GROUP BY c.id
    ORDER BY c.numero_compte
");
$stmt->execute();
$soldes_principaux = $stmt->fetchAll(PDO::FETCH_ASSOC);

// TOUS les comptes avec leurs soldes pour le plan comptable
$stmt = $pdo->prepare("
    SELECT c.id, c.nom_compte, c.numero_compte, c.type_compte, c.categorie,
           COALESCE(SUM(CASE WHEN c.type_compte IN ('actif', 'charge') THEN l.debit - l.credit ELSE l.credit - l.debit END), 0) as solde
    FROM comptes_comptables c
    LEFT JOIN lignes_ecritures l ON c.id = l.compte_id
    LEFT JOIN ecritures_comptables e ON l.ecriture_id = e.id AND e.statut = 'validee'
    -- WHERE c.est_actif = TRUE  -- Temporairement commenté pour debug
    GROUP BY c.id
    ORDER BY c.numero_compte
");
$stmt->execute();
$tous_comptes_avec_soldes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dernières écritures
$stmt = $pdo->prepare("
    SELECT e.*, 
           GROUP_CONCAT(CONCAT(c.nom_compte, ': ', 
               CASE WHEN l.debit > 0 THEN CONCAT('+', l.debit) ELSE CONCAT('-', l.credit) END
           ) SEPARATOR ' | ') as details
    FROM ecritures_comptables e
    LEFT JOIN lignes_ecritures l ON e.id = l.ecriture_id
    LEFT JOIN comptes_comptables c ON l.compte_id = c.id
    GROUP BY e.id
    ORDER BY e.date_creation DESC
    LIMIT 10
");
$stmt->execute();
$dernieres_ecritures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Opérations de trésorerie récentes
$stmt = $pdo->prepare("
    SELECT t.*, c.nom_compte, m.nom as moyen_paiement
    FROM tresorerie t
    JOIN comptes_comptables c ON t.compte_id = c.id
    JOIN moyens_paiement m ON t.moyen_paiement_id = m.id
    ORDER BY t.date_operation DESC, t.date_creation DESC
    LIMIT 15
");
$stmt->execute();
$operations_tresorerie = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Historique simple (100 derniers)
try {
    $stmt = $pdo->prepare("SELECT id, date_operation, libelle, numero_piece, montant, type_operation FROM tresorerie ORDER BY date_operation DESC, date_creation DESC LIMIT 100");
    $stmt->execute();
    $operations_simples = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $operations_simples = [];
}

// Tous les comptes comptables pour les modals
$stmt = $pdo->prepare("SELECT id, numero_compte, nom_compte, type_compte FROM comptes_comptables ORDER BY numero_compte");
$stmt->execute();
$comptes_comptables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Comptes de trésorerie pour les modals
$stmt = $pdo->prepare("SELECT id, numero_compte, nom_compte FROM comptes_comptables WHERE numero_compte LIKE '5%' ORDER BY numero_compte");
$stmt->execute();
$comptes_tresorerie = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les comptes pour les formulaires
$stmt = $pdo->query("SELECT * FROM comptes_comptables ORDER BY numero_compte");
$comptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les moyens de paiement
$stmt = $pdo->query("SELECT * FROM moyens_paiement ORDER BY nom");
$moyens_paiement = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques rapides avec budgets
// Correction: utiliser des sous-requêtes indépendantes pour éviter que l'absence d'écritures empêche le calcul des totaux de trésorerie.
$stmt = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM ecritures_comptables WHERE statut = 'brouillon') AS ecritures_brouillon,
        (SELECT COUNT(*) FROM ecritures_comptables WHERE statut = 'validee') AS ecritures_validees,
        (SELECT COALESCE(SUM(montant), 0) FROM tresorerie
            WHERE type_operation = 'entree'
              AND MONTH(date_operation) = MONTH(NOW())
              AND YEAR(date_operation) = YEAR(NOW())
        ) AS total_entrees_mois,
        (SELECT COALESCE(SUM(montant), 0) FROM tresorerie
            WHERE type_operation = 'sortie'
              AND MONTH(date_operation) = MONTH(NOW())
              AND YEAR(date_operation) = YEAR(NOW())
        ) AS total_sorties_mois
");
$statistiques = $stmt->fetch(PDO::FETCH_ASSOC);

// Préparer les détails mensuels pour impression
try {
    // Entrées (toutes périodes)
    $stmt = $pdo->prepare("
        SELECT t.date_operation, t.libelle, t.montant, t.type_operation, t.numero_piece, t.beneficiaire,
               c.numero_compte, c.nom_compte
        FROM tresorerie t
        JOIN comptes_comptables c ON t.compte_id = c.id
        WHERE t.type_operation = 'entree'
        ORDER BY t.date_operation
    ");
    $stmt->execute();
    $entrees_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sorties (toutes périodes)
    $stmt = $pdo->prepare("
        SELECT t.date_operation, t.libelle, t.montant, t.type_operation, t.numero_piece, t.beneficiaire,
               c.numero_compte, c.nom_compte
        FROM tresorerie t
        JOIN comptes_comptables c ON t.compte_id = c.id
        WHERE t.type_operation = 'sortie'
        ORDER BY t.date_operation
    ");
    $stmt->execute();
    $sorties_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mouvements Banque (512000) - toutes périodes
    $stmt = $pdo->prepare("
        SELECT t.date_operation, t.libelle, t.montant, t.type_operation, t.numero_piece, t.beneficiaire,
               c.numero_compte, c.nom_compte
        FROM tresorerie t
        JOIN comptes_comptables c ON t.compte_id = c.id
        WHERE c.numero_compte = '512000'
        ORDER BY t.date_operation
    ");
    $stmt->execute();
    $banque_ops_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mouvements Caisse (530000) - toutes périodes
    $stmt = $pdo->prepare("
        SELECT t.date_operation, t.libelle, t.montant, t.type_operation, t.numero_piece, t.beneficiaire,
               c.numero_compte, c.nom_compte
        FROM tresorerie t
        JOIN comptes_comptables c ON t.compte_id = c.id
        WHERE c.numero_compte = '530000'
        ORDER BY t.date_operation
    ");
    $stmt->execute();
    $caisse_ops_mois = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $entrees_mois = $sorties_mois = $banque_ops_mois = $caisse_ops_mois = [];
}

// Extraire les soldes Banque et Caisse pour affichage dans les détails
$solde_banque_val = 0;
$solde_caisse_val = 0;
foreach ($soldes_principaux as $solde) {
    if (($solde['numero_compte'] ?? '') === '512000') {
        $solde_banque_val = $solde['solde'] ?? 0;
    } elseif (($solde['numero_compte'] ?? '') === '530000') {
        $solde_caisse_val = $solde['solde'] ?? 0;
    }
}

// Statistiques budgétaires
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_budgets,
            COUNT(CASE WHEN statut = 'valide' THEN 1 END) as budgets_valides,
            SUM(CASE WHEN statut = 'valide' THEN montant_prevu ELSE 0 END) as montant_prevu_total,
            AVG(CASE WHEN montant_prevu > 0 THEN (montant_realise / montant_prevu * 100) ELSE 0 END) as taux_realisation_moyen
        FROM budgets 
        WHERE annee_scolaire = '$annee_courante'
    ");
    $stats_budgets = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats_budgets = [
        'total_budgets' => 0,
        'budgets_valides' => 0,
        'montant_prevu_total' => 0,
        'taux_realisation_moyen' => 0
    ];
}

// Budgets récents
try {
    $stmt = $pdo->query("
        SELECT b.*, c.nom_compte, cat.nom_categorie, cat.couleur_interface
        FROM budgets b
        LEFT JOIN comptes_comptables c ON b.compte_id = c.id
        LEFT JOIN categories_budget cat ON b.categorie_id = cat.id
        WHERE b.annee_scolaire = '$annee_courante'
        ORDER BY b.date_creation DESC
        LIMIT 10
    ");
    $budgets_recents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $budgets_recents = [];
}

// Catégories de budget
try {
    $stmt = $pdo->query("
        SELECT * FROM categories_budget 
        WHERE est_active = TRUE 
        ORDER BY ordre_affichage, nom_categorie
    ");
    $categories_budget = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories_budget = [];
}

// Alertes budgétaires
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as nb_alertes
        FROM alertes_budgetaires 
        WHERE est_traitee = FALSE
    ");
    $nb_alertes_budget = $stmt->fetch(PDO::FETCH_ASSOC)['nb_alertes'] ?? 0;
} catch (Exception $e) {
    $nb_alertes_budget = 0;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comptabilité & Trésorerie - Administration</title>
    <!-- <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->

    <!-- Tailwind CSS -->
    <script src="tailwind.js"></script>
    <!-- Font Awesome & Google Fonts -->
    <link href="all.min.css" rel="stylesheet" />
    <script src="all.min.js"></script>

    <script src="chart.js"></script>
    
    <style>
        .montant {
            font-weight: 600;
            color: #1e40af;
            font-family: 'Courier New', monospace;
        }

        .montant-compact {
            font-size: 0.9em;
            font-weight: 500;
            color: #059669;
        }

        .montant-negatif {
            color: #dc2626;
        }

        .montant-positif {
            color: #059669;
        }
        
        .montant-neutre {
            color: #1e40af;
        }
    /* Masquer l'ancien système d'onglets pour ne garder que l'interface simple */
        .tab-button, .tab-content {
            display: none !important;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">

        <!-- Contenu principal -->
        <div class="flex-1 overflow-auto">
            <!-- En-tête -->
            <div class="bg-white shadow-sm border-b px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            <i class="fas fa-calculator mr-3 text-green-600"></i>
                            Comptabilité & Trésorerie
                        </h1>
                        <p class="text-gray-600 mt-1">Gestion financière et comptable de l'établissement</p>
                    </div>
                    <div class="flex space-x-3">
                        <!-- <button onclick="ouvrirModalEcriture()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Écriture Comptable
                        </button> -->
                        <button onclick="ouvrirModalTresorerie()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-money-bill-wave mr-2"></i>Opération Trésorerie
                        </button>
                        <!-- <button onclick="ouvrirModalBudget()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-chart-line mr-2"></i>Budget
                        </button> -->
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message_succes): ?>
                <div class="mx-6 mt-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message_succes) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message_erreur): ?>
                <div class="mx-6 mt-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($message_erreur) ?>
                </div>
            <?php endif; ?>
            
            <!-- Saisie simple Trésorerie -->
            <div class="mx-6 mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Formulaire simple -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-receipt mr-2 text-blue-600"></i>Ecriture comptable (simple)
                    </h3>
                    <form method="POST" class="grid grid-cols-1 gap-4">
                        <input type="hidden" name="action" value="ajouter_tresorerie">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" name="date_operation" value="<?= date('Y-m-d') ?>" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="type_operation" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    <option value="entree">Recette (Entrée)</option>
                                    <option value="sortie" selected>Dépense (Sortie)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Libellé</label>
                            <input type="text" name="libelle" placeholder="Ex: Achat de materiel informatique" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Montant (FG)</label>
                                <input type="number" name="montant" step="0.01" min="0.01" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Numéro du reçu</label>
                                <input type="text" name="numero_piece" placeholder="Ex: R-2025-00123"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                                <i class="fas fa-save mr-2"></i>Enregistrer
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Historique simple -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-clock mr-2 text-gray-600"></i>Historique (100 derniers)
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Libellé</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reçu</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($operations_simples)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">Aucun mouvement enregistré</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($operations_simples as $op): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-sm text-gray-500"><?= date('d/m/Y', strtotime($op['date_operation'])) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($op['libelle']) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($op['numero_piece'] ?: '-') ?></td>
                                            <td class="px-4 py-3 text-sm font-medium montant <?= $op['type_operation'] === 'entree' ? 'montant-positif' : 'montant-negatif' ?>">
                                                <?= ($op['type_operation'] === 'entree' ? '+' : '-') . formatMontantFG($op['montant']) ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                <div class="flex items-center justify-between">
                                                    <span class="px-2 py-1 text-xs rounded-full <?= $op['type_operation'] === 'entree' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                        <?= $op['type_operation'] === 'entree' ? 'Recette' : 'Dépense' ?>
                                                    </span>
                                                    <div class="flex space-x-2">
                                                        <button onclick="chargerTresoreriePourModification(<?= $op['id'] ?>)" class="text-blue-600 hover:text-blue-800 px-2 py-1 rounded" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="supprimerTresorerie(<?= $op['id'] ?>)" class="text-red-600 hover:text-red-800 px-2 py-1 rounded" title="Supprimer">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Alertes budgétaires -->
            <?php if ($nb_alertes_budget > 0): ?>
                <div class="mx-6 mt-4 p-4 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="font-medium">Attention !</span>
                        <span class="ml-2"><?= $nb_alertes_budget ?> alerte(s) budgétaire(s) nécessite(nt) votre attention.</span>
                        <button onclick="changerTab('budgets')" class="ml-4 text-yellow-800 underline hover:text-yellow-900">
                            Voir les budgets
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistiques rapides -->
            <div class="mx-6 mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Solde Banque -->
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100">
                                <i class="fas fa-university text-blue-600 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <span class="text-xs text-gray-600">Solde Banque</span>
                                <p class="text-lg font-semibold montant montant-positif">
                                    <?php
                                    $solde_banque = 0;
                                    foreach ($soldes_principaux as $solde) {
                                        if (($solde['numero_compte'] ?? '') === '512000') {
                                            $solde_banque = $solde['solde'] ?? 0;
                                            break;
                                        }
                                    }
                                    echo formatMontantCompactFG($solde_banque);
                                    ?>
                                </p>
                            </div>
                        </div>
                        <button class="text-gray-500 hover:text-gray-700" title="Détails imprimables" onclick="window.imprimerDetails('banque')">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>

                <!-- Solde Caisse -->
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100">
                                <i class="fas fa-cash-register text-green-600 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <span class="text-xs text-gray-600">Solde Caisse</span>
                                <p class="text-lg font-semibold montant montant-positif">
                                    <?php
                                    $solde_caisse = 0;
                                    foreach ($soldes_principaux as $solde) {
                                        if (($solde['numero_compte'] ?? '') === '530000') {
                                            $solde_caisse = $solde['solde'] ?? 0;
                                            break;
                                        }
                                    }
                                    echo formatMontantCompactFG($solde_caisse);
                                    ?>
                                </p>
                            </div>
                        </div>
                        <button class="text-gray-500 hover:text-gray-700" title="Détails imprimables" onclick="window.imprimerDetails('caisse')">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>

                <!-- Entrées Mois -->
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100">
                                <i class="fas fa-arrow-up text-purple-600 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <span class="text-xs text-gray-600">Entrées Mois</span>
                                <p class="text-lg font-semibold montant montant-positif">
                                    <?= formatMontantCompactFG($statistiques['total_entrees_mois']) ?>
                                </p>
                            </div>
                        </div>
                        <button class="text-gray-500 hover:text-gray-700" title="Détails imprimables" onclick="window.imprimerDetails('entrees')">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>

                <!-- Sorties Mois -->
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100">
                                <i class="fas fa-arrow-down text-red-600 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <span class="text-xs text-gray-600">Sorties Mois</span>
                                <p class="text-lg font-semibold montant montant-negatif">
                                    <?= formatMontantCompactFG($statistiques['total_sorties_mois']) ?>
                                </p>
                            </div>
                        </div>
                        <button class="text-gray-500 hover:text-gray-700" title="Détails imprimables" onclick="window.imprimerDetails('sorties')">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mx-6 mt-8">
                <div class="border-b border-gray-200 bg-white pb-px">

                    <nav class="-mb-px flex space-x-8">
                        <button onclick="changerTab('ecritures')" id="tab-ecritures" class="tab-button active py-2 px-1 border-b-2 border-green-500 font-medium text-sm text-green-600">
                            <i class="fas fa-book mr-2"></i>Écritures Comptables
                        </button>
                        <button onclick="changerTab('tresorerie')" id="tab-tresorerie" class="tab-button py-2 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            <i class="fas fa-money-bill-wave mr-2"></i>Trésorerie
                        </button>
                        <button onclick="changerTab('comptes')" id="tab-comptes" class="tab-button py-2 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            <i class="fas fa-list mr-2"></i>Plan Comptable
                        </button>
                        <button onclick="changerTab('budgets')" id="tab-budgets" class="tab-button py-2 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            <i class="fas fa-chart-line mr-2"></i>Budgets
                            <?php if ($nb_alertes_budget > 0): ?>
                                <span class="ml-1 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full"><?= $nb_alertes_budget ?></span>
                            <?php endif; ?>
                        </button>
                    </nav>
                </div>

                <!-- Contenu des tabs -->
                <div class="mt-6">
                    <!-- Tab Écritures Comptables -->
                    <div id="content-ecritures" class="tab-content">
                        <div class="bg-white rounded-lg shadow">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Dernières Écritures Comptables</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">N° Pièce</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Libellé</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (empty($dernieres_ecritures)): ?>
                                            <tr>
                                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                                    <i class="fas fa-book text-4xl mb-4 block text-gray-400"></i>
                                                    Aucune écriture comptable enregistrée
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($dernieres_ecritures as $ecriture): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($ecriture['numero_piece']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= date('d/m/Y', strtotime($ecriture['date_ecriture'])) ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-900">
                                                        <?= htmlspecialchars($ecriture['libelle']) ?>
                                                        <?php if ($ecriture['details']): ?>
                                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($ecriture['details']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm montant montant-neutre">
                                                        <?= formatMontantFG($ecriture['montant_total']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= 
                                                            $ecriture['statut'] === 'validee' ? 'bg-green-100 text-green-800' :
                                                            ($ecriture['statut'] === 'brouillon' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')
                                                        ?>">
                                                            <?= ucfirst($ecriture['statut']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div class="flex space-x-2">
                                                            <?php if ($ecriture['statut'] === 'brouillon'): ?>
                                                                <button onclick="validerEcriture(<?= $ecriture['id'] ?>)" 
                                                                        class="text-green-600 hover:text-green-900 px-2 py-1 rounded hover:bg-green-50"
                                                                        title="Valider l'écriture">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                                <button onclick="modifierEcriture(<?= $ecriture['id'] ?>)" 
                                                                        class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded hover:bg-blue-50"
                                                                        title="Modifier l'écriture">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button onclick="supprimerEcriture(<?= $ecriture['id'] ?>)" 
                                                                        class="text-red-600 hover:text-red-900 px-2 py-1 rounded hover:bg-red-50"
                                                                        title="Supprimer l'écriture">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-gray-400 text-xs bg-gray-100 px-2 py-1 rounded" title="Écriture validée - non modifiable">
                                                                    <i class="fas fa-lock mr-1"></i>Validée
                                                                </span>
                                                            <?php endif; ?>
                                                            <button onclick="voirEcriture(<?= $ecriture['id'] ?>)" 
                                                                    class="text-indigo-600 hover:text-indigo-900 px-2 py-1 rounded hover:bg-indigo-50"
                                                                    title="Voir les détails">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Trésorerie -->
                    <div id="content-tresorerie" class="tab-content hidden">
                        <div class="bg-white rounded-lg shadow">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Opérations de Trésorerie</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Libellé</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Compte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Moyen</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (empty($operations_tresorerie)): ?>
                                            <tr>
                                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                                    <i class="fas fa-money-bill-wave text-4xl mb-4 block text-gray-400"></i>
                                                    Aucune opération de trésorerie enregistrée
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($operations_tresorerie as $operation): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= date('d/m/Y', strtotime($operation['date_operation'])) ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-900">
                                                        <?= htmlspecialchars($operation['libelle']) ?>
                                                        <?php if ($operation['beneficiaire']): ?>
                                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($operation['beneficiaire']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= htmlspecialchars($operation['nom_compte']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= htmlspecialchars($operation['moyen_paiement']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium montant <?= $operation['type_operation'] === 'entree' ? 'montant-positif' : 'montant-negatif' ?>">
                                                        <?= $operation['type_operation'] === 'entree' ? '+' : '-' ?><?= formatMontantFG($operation['montant']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="px-2 py-1 text-xs rounded-full <?= $operation['type_operation'] === 'entree' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                            <?= ucfirst($operation['categorie']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div class="flex space-x-2">
                                                            <button onclick="chargerTresoreriePourModification(<?= $operation['id'] ?>)" 
                                                                    class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded hover:bg-blue-50"
                                                                    title="Modifier l'opération">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button onclick="supprimerTresorerie(<?= $operation['id'] ?>)" 
                                                                    class="text-red-600 hover:text-red-900 px-2 py-1 rounded hover:bg-red-50"
                                                                    title="Supprimer l'opération">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Plan Comptable -->
                    <div id="content-comptes" class="tab-content hidden">
                        <div class="bg-white rounded-lg shadow">
                            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                                <h3 class="text-lg font-semibold text-gray-900">Plan Comptable & Soldes</h3>
                                <button onclick="ouvrirModalNouveauCompte()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">
                                    <i class="fas fa-plus mr-2"></i>Nouveau Compte
                                </button>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">N° Compte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom du Compte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catégorie</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Solde</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($tous_comptes_avec_soldes as $compte): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($compte['numero_compte']) ?>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-900">
                                                    <?= htmlspecialchars($compte['nom_compte']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <span class="px-2 py-1 text-xs rounded-full <?= 
                                                        $compte['type_compte'] === 'actif' ? 'bg-blue-100 text-blue-800' :
                                                        ($compte['type_compte'] === 'passif' ? 'bg-purple-100 text-purple-800' :
                                                        ($compte['type_compte'] === 'charge' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'))
                                                    ?>">
                                                        <?= ucfirst($compte['type_compte']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= $compte['categorie'] ? ucfirst(str_replace('_', ' ', $compte['categorie'])) : '-' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-right montant <?= $compte['solde'] >= 0 ? 'montant-positif' : 'montant-negatif' ?>">
                                                    <?= formatMontantFG($compte['solde']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <?php if (abs($compte['solde']) == 0): ?>
                                                            <button onclick="supprimerCompte(<?= $compte['id'] ?? 0 ?>, '<?= htmlspecialchars($compte['numero_compte']) ?>', '<?= htmlspecialchars($compte['nom_compte']) ?>')" 
                                                                    class="text-red-600 hover:text-red-900 px-2 py-1 rounded hover:bg-red-50"
                                                                    title="Supprimer le compte">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-gray-400 px-2 py-1" title="Impossible de supprimer - Solde non nul">
                                                                <i class="fas fa-lock"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($tous_comptes_avec_soldes)): ?>
                                            <tr>
                                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                                    <i class="fas fa-calculator text-2xl mb-2"></i>
                                                    <p>Aucun compte comptable trouvé</p>
                                                    <p class="text-sm">Cliquez sur "Nouveau Compte" pour créer votre premier compte</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Budgets -->
                    <div id="content-budgets" class="tab-content hidden">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Indicateurs budgétaires -->
                            <div class="lg:col-span-3 grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="bg-white rounded-lg shadow p-4">
                                    <div class="flex items-center">
                                        <div class="p-3 rounded-full bg-blue-100">
                                            <i class="fas fa-list text-blue-600"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-gray-600">Total Budgets</p>
                                            <p class="text-xl font-semibold text-gray-900"><?= $stats_budgets['total_budgets'] ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg shadow p-4">
                                    <div class="flex items-center">
                                        <div class="p-3 rounded-full bg-green-100">
                                            <i class="fas fa-check text-green-600"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-gray-600">Budgets Validés</p>
                                            <p class="text-xl font-semibold text-green-600"><?= $stats_budgets['budgets_valides'] ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg shadow p-4">
                                    <div class="flex items-center">
                                        <div class="p-3 rounded-full bg-indigo-100">
                                            <i class="fas fa-coins text-indigo-600"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-gray-600">Montant Prévu</p>
                                            <p class="text-lg font-semibold montant montant-neutre"><?= formatMontantCompactFG($stats_budgets['montant_prevu_total']) ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg shadow p-4">
                                    <div class="flex items-center">
                                        <div class="p-3 rounded-full bg-yellow-100">
                                            <i class="fas fa-chart-line text-yellow-600"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-gray-600">Taux Réalisation</p>
                                            <p class="text-xl font-semibold text-yellow-600"><?= number_format($stats_budgets['taux_realisation_moyen'], 1) ?>%</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Liste des budgets -->
                            <div class="lg:col-span-2">
                                <div class="bg-white rounded-lg shadow">
                                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                                        <h3 class="text-lg font-semibold text-gray-900">Budgets Récents</h3>
                                        <button onclick="ouvrirModalBudget()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded text-sm">
                                            <i class="fas fa-plus mr-1"></i>Nouveau
                                        </button>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Compte</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prévu</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Réalisé</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php if (empty($budgets_recents)): ?>
                                                    <tr>
                                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                                            <i class="fas fa-chart-line text-3xl mb-3 block text-gray-400"></i>
                                                            Aucun budget configuré pour cette année
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($budgets_recents as $budget): ?>
                                                        <tr class="hover:bg-gray-50">
                                                            <td class="px-4 py-4 text-sm">
                                                                <div class="font-medium text-gray-900"><?= htmlspecialchars($budget['nom_compte'] ?? 'Compte supprimé') ?></div>
                                                                <?php if ($budget['nom_categorie']): ?>
                                                                    <div class="text-xs text-gray-500 mt-1">
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" 
                                                                              style="background-color: <?= $budget['couleur_interface'] ?>20; color: <?= $budget['couleur_interface'] ?>">
                                                                            <?= htmlspecialchars($budget['nom_categorie']) ?>
                                                                        </span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-4 py-4 whitespace-nowrap text-sm montant montant-neutre">
                                                                <?= formatMontantCompactFG($budget['montant_prevu']) ?>
                                                            </td>
                                                            <td class="px-4 py-4 whitespace-nowrap text-sm">
                                                                <div class="montant <?= $budget['montant_realise'] >= $budget['montant_prevu'] ? 'montant-negatif' : 'montant-positif' ?>">
                                                                    <?= formatMontantCompactFG($budget['montant_realise']) ?>
                                                                </div>
                                                                <?php 
                                                                $pourcentage = $budget['montant_prevu'] > 0 ? ($budget['montant_realise'] / $budget['montant_prevu'] * 100) : 0;
                                                                ?>
                                                                <div class="text-xs text-gray-500 mt-1"><?= number_format($pourcentage, 1) ?>%</div>
                                                            </td>
                                                            <td class="px-4 py-4 whitespace-nowrap">
                                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= 
                                                                    $budget['statut'] === 'valide' ? 'bg-green-100 text-green-800' : 
                                                                    ($budget['statut'] === 'previsionnel' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')
                                                                ?>">
                                                                    <?= ucfirst($budget['statut']) ?>
                                                                </span>
                                                            </td>
                                                            <td class="px-4 py-4 whitespace-nowrap text-sm font-medium">
                                                                <div class="flex space-x-2">
                                                                    <?php if ($budget['statut'] === 'previsionnel'): ?>
                                                                        <button onclick="validerBudget(<?= $budget['id'] ?>)" 
                                                                                class="text-green-600 hover:text-green-900 px-2 py-1 rounded hover:bg-green-50"
                                                                                title="Valider le budget">
                                                                            <i class="fas fa-check"></i>
                                                                        </button>
                                                                        <button onclick="supprimerBudget(<?= $budget['id'] ?>, '<?= htmlspecialchars($budget['annee_scolaire']) ?>', '<?= htmlspecialchars($budget['trimestre']) ?>')" 
                                                                                class="text-red-600 hover:text-red-900 px-2 py-1 rounded hover:bg-red-50"
                                                                                title="Supprimer le budget">
                                                                            <i class="fas fa-trash"></i>
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <span class="text-gray-400 text-xs bg-gray-100 px-2 py-1 rounded" title="Budget validé - non modifiable">
                                                                            <i class="fas fa-lock mr-1"></i>Validé
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <button onclick="voirBudget(<?= $budget['id'] ?>)" 
                                                                            class="text-indigo-600 hover:text-indigo-900 px-2 py-1 rounded hover:bg-indigo-50"
                                                                            title="Voir détails">
                                                                        <i class="fas fa-eye"></i>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Graphique répartition par catégories -->
                            <div class="lg:col-span-1">
                                <div class="bg-white rounded-lg shadow p-4">
                                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Répartition par Catégorie</h4>
                                    <div style="position: relative; height: 320px; overflow: hidden;">
                                        <canvas id="graphiqueBudgets" style="max-width: 100%; max-height: 300px;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Écriture Comptable -->
    <div id="modalEcriture" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Nouvelle Écriture Comptable</h3>
                        <button onclick="fermerModalEcriture()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="ajouter_ecriture">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date d'écriture <span class="text-red-500">*</span></label>
                            <input type="date" name="date_ecriture" value="<?= date('Y-m-d') ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Montant (FG) <span class="text-red-500">*</span></label>
                            <input type="number" name="montant" step="0.01" min="0.01" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Libellé <span class="text-red-500">*</span></label>
                        <input type="text" name="libelle" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte à débiter <span class="text-red-500">*</span></label>
                            <select name="compte_debit" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= $compte['id'] ?>">
                                        <?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte à créditer <span class="text-red-500">*</span></label>
                            <select name="compte_credit" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= $compte['id'] ?>">
                                        <?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Observations</label>
                        <textarea name="observations" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalEcriture()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Trésorerie -->
    <div id="modalTresorerie" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Nouvelle Opération de Trésorerie</h3>
                        <button onclick="fermerModalTresorerie()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6" id="formTresorerieSimple">
                    <input type="hidden" name="action" value="ajouter_tresorerie">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date opération <span class="text-red-500">*</span></label>
                            <input type="date" name="date_operation" value="<?= date('Y-m-d') ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type <span class="text-red-500">*</span></label>
                            <select name="type_operation" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="entree">Entrée</option>
                                <option value="sortie">Sortie</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte <span class="text-red-500">*</span></label>
                            <select name="compte_id" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= $compte['id'] ?>">
                                        <?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Montant (FG) <span class="text-red-500">*</span></label>
                            <input type="number" name="montant" step="0.01" min="0.01" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Libellé <span class="text-red-500">*</span></label>
                        <input type="text" name="libelle" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">N° Pièce</label>
                            <input type="text" name="numero_piece" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalTresorerie()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Budget -->
    <div id="modalBudget" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Nouveau Budget</h3>
                        <button onclick="fermerModalBudget()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="ajouter_budget">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Année scolaire <span class="text-red-500">*</span></label>
                            <input type="text" name="annee_scolaire" value="<?= $annee_courante ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Trimestre <span class="text-red-500">*</span></label>
                            <select name="trimestre" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="annuel">Annuel</option>
                                <option value="T1">Trimestre 1</option>
                                <option value="T2">Trimestre 2</option>
                                <option value="T3">Trimestre 3</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte <span class="text-red-500">*</span></label>
                            <select name="compte_id" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes as $compte): ?>
                                    <option value="<?= $compte['id'] ?>">
                                        <?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                            <select name="categorie_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Aucune catégorie</option>
                                <?php foreach ($categories_budget as $categorie): ?>
                                    <option value="<?= $categorie['id'] ?>">
                                        <?= htmlspecialchars($categorie['nom_categorie']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Montant prévu (FG) <span class="text-red-500">*</span></label>
                        <input type="number" name="montant_prevu" step="0.01" min="0.01" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Commentaires</label>
                        <textarea name="commentaires" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalBudget()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Configuration de la devise
        const DEVISE_FG = {
            code: 'FG',
            symbole: 'FG',
            sepMilliers: ' ',
            sepDecimales: ',',
            position: 'apres'
        };

        // SweetAlert2 fallback (si non chargé)
        if (typeof window.Swal === 'undefined') {
            window.Swal = {
                fire: function(opts) {
                    const title = (opts && opts.title) ? opts.title : '';
                    const msg = (opts && (opts.text || opts.html)) ? (opts.text || opts.html) : '';
                    const needConfirm = (opts && opts.icon === 'warning') || (opts && opts.showCancelButton);
                    if (needConfirm) {
                        const ok = confirm(title + (msg ? "\n" + msg : ""));
                        return Promise.resolve({ isConfirmed: ok });
                    } else {
                        alert(title + (msg ? "\n" + msg : ""));
                        return Promise.resolve({ isConfirmed: true });
                    }
                }
            };
        }

        // Fonctions de formatage JavaScript
        function formatMontantFG(montant, avecDevise = true) {
            if (montant === null || montant === undefined || isNaN(montant)) {
                return avecDevise ? '0 FG' : '0';
            }
            
            const montantNum = parseFloat(montant);
            const formatte = montantNum.toLocaleString('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            return avecDevise ? formatte + ' FG' : formatte;
        }

        function formatMontantCompactFG(montant) {
            if (montant === null || montant === undefined || isNaN(montant)) {
                return '0 FG';
            }
            
            const montantNum = Math.abs(parseFloat(montant));
            
            if (montantNum >= 1000000000) {
                return (montantNum / 1000000000).toFixed(1) + 'B FG';
            } else if (montantNum >= 1000000) {
                return (montantNum / 1000000).toFixed(1) + 'M FG';
            } else if (montantNum >= 1000) {
                return (montantNum / 1000).toFixed(1) + 'K FG';
            }
            
            return montantNum.toFixed(2) + ' FG';
        }

        // Données pour détails imprimables (injectées depuis PHP)
        window.DETAILS = {
            entrees: <?= json_encode($entrees_mois ?? [], JSON_UNESCAPED_UNICODE) ?>,
            sorties: <?= json_encode($sorties_mois ?? [], JSON_UNESCAPED_UNICODE) ?>,
            banque: <?= json_encode($banque_ops_mois ?? [], JSON_UNESCAPED_UNICODE) ?>,
            caisse: <?= json_encode($caisse_ops_mois ?? [], JSON_UNESCAPED_UNICODE) ?>,
            solde_banque: <?= json_encode($solde_banque_val ?? 0) ?>,
            solde_caisse: <?= json_encode($solde_caisse_val ?? 0) ?>
        };
        // Comptes disponibles (pour injection dynamique des champs Débit/Crédit dans la modale Trésorerie)
        window.ACCOUNTS = <?= json_encode($comptes ?? [], JSON_UNESCAPED_UNICODE) ?>;

        // Gestion des tabs
        function changerTab(tab) {
            // Masquer tous les contenus
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Désactiver tous les boutons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active', 'border-green-500', 'text-green-600');
                button.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Activer le tab sélectionné
            document.getElementById('content-' + tab).classList.remove('hidden');
            const activeButton = document.getElementById('tab-' + tab);
            activeButton.classList.remove('border-transparent', 'text-gray-500');
            activeButton.classList.add('active', 'border-green-500', 'text-green-600');
            
            // Charger le graphique des budgets si nécessaire
            if (tab === 'budgets') {
                setTimeout(() => {
                    chargerGraphiqueBudgets();
                }, 100);
            }
        }
        
        // Gestion des modals
        function ouvrirModalEcriture() {
            document.getElementById('modalEcriture').classList.remove('hidden');
        }
        
        function fermerModalEcriture() {
            document.getElementById('modalEcriture').classList.add('hidden');
        }
        
        function ouvrirModalTresorerie() {
            document.getElementById('modalTresorerie').classList.remove('hidden');
        }
        
        function fermerModalTresorerie() {
            document.getElementById('modalTresorerie').classList.add('hidden');
        }
        
        function ouvrirModalBudget() {
            document.getElementById('modalBudget').classList.remove('hidden');
        }
        
        function fermerModalBudget() {
            document.getElementById('modalBudget').classList.add('hidden');
        }
        
        function validerEcriture(id) {
            Swal.fire({
                title: 'Valider l\'écriture ?',
                text: 'Cette action rendra l\'écriture définitive et non modifiable.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, valider',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="valider_ecriture">
                        <input type="hidden" name="ecriture_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function validerBudget(id) {
            Swal.fire({
                title: 'Valider le budget ?',
                text: 'Cette action validera le budget et permettra le suivi des réalisations.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, valider',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="valider_budget">
                        <input type="hidden" name="budget_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function modifierEcriture(id) {
            // Charger les données de l'écriture pour pré-remplir le modal
            chargerEcriturePourModification(id);
        }
        
        function supprimerEcriture(id) {
            Swal.fire({
                title: 'Supprimer l\'écriture ?',
                text: 'Cette action est irréversible. L\'écriture sera définitivement supprimée.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler',
                dangerMode: true
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="supprimer_ecriture">
                        <input type="hidden" name="ecriture_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function voirEcriture(id) {
            // Afficher les détails de l'écriture
            Swal.fire({
                title: 'Détails de l\'écriture',
                text: 'Fonctionnalité de visualisation détaillée en cours de développement.',
                icon: 'info',
                confirmButtonColor: '#6366f1',
                confirmButtonText: 'OK'
            });
        }
        
        function voirBudget(id) {
            // Implémentation à venir pour voir les détails d'un budget
            alert('Fonctionnalité de visualisation en cours de développement');
        }
        
        // Graphique des budgets par catégorie
        function chargerGraphiqueBudgets() {
            const canvas = document.getElementById('graphiqueBudgets');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            
            // Données factices pour la démo - à remplacer par des données réelles via AJAX
            const data = {
                labels: ['Recettes Scolarité', 'Subventions', 'Charges Personnel', 'Charges Fonctionnement', 'Investissements'],
                datasets: [{
                    data: [45, 20, 15, 12, 8],
                    backgroundColor: [
                        '#10B981',
                        '#8B5CF6', 
                        '#EF4444',
                        '#F59E0B',
                        '#3B82F6'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Fermer les modals en cliquant à l'extérieur
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('fixed') && e.target.classList.contains('inset-0')) {
                e.target.classList.add('hidden');
            }
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Charger le graphique si on est sur l'onglet budgets
            if (!document.getElementById('content-budgets').classList.contains('hidden')) {
                setTimeout(chargerGraphiqueBudgets, 100);
            }
        });
        
        // Fonctions pour les modals de modification
        function ouvrirModalModifierEcriture() {
            document.getElementById('modalModifierEcriture').classList.remove('hidden');
        }
        
        function fermerModalModifierEcriture() {
            document.getElementById('modalModifierEcriture').classList.add('hidden');
        }
        
        function ouvrirModalModifierTresorerie() {
            document.getElementById('modalModifierTresorerie').classList.remove('hidden');
        }
        
        function fermerModalModifierTresorerie() {
            document.getElementById('modalModifierTresorerie').classList.add('hidden');
        }
        
        function ouvrirModalNouveauCompte() {
            document.getElementById('modalNouveauCompte').classList.remove('hidden');
        }
        
        function fermerModalNouveauCompte() {
            document.getElementById('modalNouveauCompte').classList.add('hidden');
        }
        
        // Charger les données d'une écriture pour modification
        async function chargerEcriturePourModification(id) {
            try {
                const response = await fetch(`ajax_get_ecriture.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('modifier_ecriture_id').value = id;
                    document.getElementById('modifier_libelle').value = data.ecriture.libelle;
                    document.getElementById('modifier_montant').value = data.ecriture.montant_total;
                    document.getElementById('modifier_observations').value = data.ecriture.observations || '';
                    
                    // Sélectionner les comptes
                    if (data.lignes.length >= 2) {
                        document.getElementById('modifier_compte_debit').value = data.lignes[0].compte_id;
                        document.getElementById('modifier_compte_credit').value = data.lignes[1].compte_id;
                    }
                    
                    ouvrirModalModifierEcriture();
                } else {
                    Swal.fire('Erreur', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Erreur', 'Impossible de charger les données de l\'écriture', 'error');
            }
        }
        
        // Charger les données d'une opération de trésorerie pour modification
        async function chargerTresoreriePourModification(id) {
            try {
                const response = await fetch(`comptabilite.php?action=get_tresorerie&id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const operation = data.operation;
                    document.getElementById('modifier_tresorerie_id').value = id;
                    document.getElementById('modifier_date_operation').value = operation.date_operation;
                    document.getElementById('modifier_type_operation').value = operation.type_operation;
                    document.getElementById('modifier_tresorerie_libelle').value = operation.libelle;
                    document.getElementById('modifier_tresorerie_montant').value = operation.montant;
                    document.getElementById('modifier_tresorerie_compte').value = operation.compte_id;
                    document.getElementById('modifier_moyen_paiement').value = operation.moyen_paiement_id;
                    document.getElementById('modifier_beneficiaire').value = operation.beneficiaire || '';
                    
                    ouvrirModalModifierTresorerie();
                } else {
                    Swal.fire('Erreur', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Erreur', 'Impossible de charger les données de l\'opération', 'error');
            }
        }
        
        // Suppression d'opération de trésorerie
        function supprimerTresorerie(id) {
            Swal.fire({
                title: 'Supprimer l\'opération ?',
                text: 'Cette action est irréversible. L\'opération de trésorerie sera définitivement supprimée.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="supprimer_tresorerie">
                        <input type="hidden" name="tresorerie_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Suppression de compte comptable
        function supprimerCompte(id, numeroCompte, nomCompte) {
            Swal.fire({
                title: 'Supprimer le compte ?',
                html: `Êtes-vous sûr de vouloir supprimer le compte :<br><strong>${numeroCompte} - ${nomCompte}</strong><br><br><span class="text-red-600">Attention : Cette action est irréversible !</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="supprimer_compte">
                        <input type="hidden" name="compte_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Suppression de budget
        function supprimerBudget(id, annee, trimestre) {
            Swal.fire({
                title: 'Supprimer le budget ?',
                html: `Êtes-vous sûr de vouloir supprimer le budget :<br><strong>${annee} - ${trimestre}</strong><br><br><span class="text-red-600">Attention : Cette action supprimera aussi les lignes budgétaires associées !</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="supprimer_budget">
                        <input type="hidden" name="budget_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>

    <!-- Modal Modification Écriture -->
    <div id="modalModifierEcriture" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Modifier l'Écriture Comptable</h3>
                        <button onclick="fermerModalModifierEcriture()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6" id="formModifierEcriture">
                    <input type="hidden" name="action" value="modifier_ecriture">
                    <input type="hidden" name="ecriture_id" id="modifier_ecriture_id">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Libellé <span class="text-red-500">*</span></label>
                            <input type="text" name="libelle" id="modifier_libelle" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Montant (FG) <span class="text-red-500">*</span></label>
                            <input type="number" name="montant" id="modifier_montant" step="0.01" min="0.01" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte à débiter <span class="text-red-500">*</span></label>
                            <select name="compte_debit" id="modifier_compte_debit" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes_comptables as $compte): ?>
                                    <option value="<?= $compte['id'] ?>"><?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte à créditer <span class="text-red-500">*</span></label>
                            <select name="compte_credit" id="modifier_compte_credit" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes_comptables as $compte): ?>
                                    <option value="<?= $compte['id'] ?>"><?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Observations</label>
                        <textarea name="observations" id="modifier_observations" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalModifierEcriture()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                            <i class="fas fa-save mr-2"></i>Modifier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modification Trésorerie -->
    <div id="modalModifierTresorerie" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Modifier l'Opération de Trésorerie</h3>
                        <button onclick="fermerModalModifierTresorerie()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6" id="formModifierTresorerie">
                    <input type="hidden" name="action" value="modifier_tresorerie">
                    <input type="hidden" name="tresorerie_id" id="modifier_tresorerie_id">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date <span class="text-red-500">*</span></label>
                            <input type="date" name="date_operation" id="modifier_date_operation" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type d'opération <span class="text-red-500">*</span></label>
                            <select name="type_operation" id="modifier_type_operation" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="entree">Entrée de fonds</option>
                                <option value="sortie">Sortie de fonds</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Libellé <span class="text-red-500">*</span></label>
                            <input type="text" name="libelle" id="modifier_tresorerie_libelle" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Montant (FG) <span class="text-red-500">*</span></label>
                            <input type="number" name="montant" id="modifier_tresorerie_montant" step="0.01" min="0.01" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Compte <span class="text-red-500">*</span></label>
                            <select name="compte_id" id="modifier_tresorerie_compte" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Sélectionner un compte</option>
                                <?php foreach ($comptes_tresorerie as $compte): ?>
                                    <option value="<?= $compte['id'] ?>"><?= $compte['numero_compte'] ?> - <?= $compte['nom_compte'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Moyen de paiement <span class="text-red-500">*</span></label>
                            <select name="moyen_paiement_id" id="modifier_moyen_paiement" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="1">Espèces</option>
                                <option value="2">Chèque</option>
                                <option value="3">Virement</option>
                                <option value="4">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bénéficiaire</label>
                        <input type="text" name="beneficiaire" id="modifier_beneficiaire" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalModifierTresorerie()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">
                            <i class="fas fa-save mr-2"></i>Modifier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Nouveau Compte -->
    <div id="modalNouveauCompte" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Nouveau Compte Comptable</h3>
                        <button onclick="fermerModalNouveauCompte()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="ajouter_compte">
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Numéro de compte <span class="text-red-500">*</span></label>
                            <input type="text" name="numero_compte" required 
                                   placeholder="ex: 411000" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nom du compte <span class="text-red-500">*</span></label>
                            <input type="text" name="nom_compte" required 
                                   placeholder="ex: Clients" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type de compte <span class="text-red-500">*</span></label>
                            <select name="type_compte" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Sélectionner un type</option>
                                <option value="actif">Actif</option>
                                <option value="passif">Passif</option>
                                <option value="charge">Charge</option>
                                <option value="produit">Produit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                            <select name="categorie" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="banque">Banque</option>
                                <option value="caisse">Caisse</option>
                                <option value="creances">Créances</option>
                                <option value="dettes">Dettes</option>
                                <option value="immobilisations">Immobilisations</option>
                                <option value="stocks">Stocks</option>
                                <option value="charges_exploitation">Charges d'exploitation</option>
                                <option value="produits_exploitation">Produits d'exploitation</option>
                                <option value="charges_financieres">Charges financières</option>
                                <option value="produits_financiers">Produits financiers</option>
                                <option value="charges_exceptionnelles">Charges exceptionnelles</option>
                                <option value="produits_exceptionnels">Produits exceptionnels</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" rows="2" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="fermerModalNouveauCompte()" 
                                class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg">
                            Annuler
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Créer le compte
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script>
// Fallback robuste pour Détails imprimables avec filtre de mois et titre dynamique
(function(){
    // Ouverture du popup et rendu
    window.imprimerDetails = function(kind) {
        try {
            const DETAILS = window.DETAILS || {};
            const data = Array.isArray(DETAILS[kind]) ? DETAILS[kind] : [];
            const soldeActuel = (kind === 'banque') ? DETAILS.solde_banque : (kind === 'caisse' ? DETAILS.solde_caisse : null);

            const titreMap = {
                entrees: 'Détails des entrées (mois en cours)',
                sorties: 'Détails des sorties (mois en cours)',
                banque: 'Détails des mouvements Banque (mois en cours)',
                caisse: 'Détails des mouvements Caisse (mois en cours)'
            };
            const baseTitle = titreMap[kind] || 'Détails';

            const css = `
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #111; }
                    .header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; }
                    .title { font-size: 18px; font-weight: 800; }
                    .meta { color: #6b7280; font-size: 12px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                    th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 10px; font-size: 13px; text-align: left; }
                    th { background: #f9fafb; color: #374151; }
                    .total { margin-top: 10px; text-align: right; font-weight: 800; }
                    .actions { margin-top: 16px; text-align: center; }
                    .btn { display: inline-block; padding: 8px 16px; border-radius: 8px; background: #2563eb; color: #fff; border: none; cursor: pointer; }
                    @media print { .actions { display: none; } }
                </style>
            `;

            const fmt = function(val) {
                const n = parseFloat(val || 0);
                return n.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' FG';
            };

            const rowsHtml = data.map(x => {
                const d = x.date_operation ? new Date(x.date_operation) : null;
                const mois = d ? (d.getMonth() + 1) : '';
                const annee = d ? d.getFullYear() : '';
                const dateTxt = d ? d.toLocaleDateString('fr-FR') : '';
                return `
                    <tr data-month="${mois}" data-year="${annee}">
                        <td>${dateTxt}</td>
                        <td>${(x.libelle || '').toString()}</td>
                        <td>${(x.numero_compte || '')} ${(x.nom_compte || '')}</td>
                        <td>${(x.numero_piece || '')}</td>
                        <td>${(x.beneficiaire || '')}</td>
                        <td style="text-align:right">${fmt(x.montant || 0)}</td>
                    </tr>
                `;
            }).join('');

            const totalInitial = data.reduce((acc, x) => acc + (parseFloat(x.montant) || 0), 0);

            const html = `
                <html><head><meta charset="utf-8"><title>${baseTitle}</title>${css}</head>
                <body>
                    <div class="header">
                        <div class="title">${baseTitle}</div>
                        <div class="meta">
                            <label style="margin-right:8px;">Filtrer par mois:</label>
                            <select id="filtreMois" style="padding:4px 8px; border:1px solid #d1d5db; border-radius:6px;">
                                <option value="0">Tous les mois</option>
                                ${[...Array(12)].map((_,i)=>`<option value="${i+1}" ${i+1=== (new Date().getMonth()+1) ? 'selected' : ''}>${new Date(2000,i,1).toLocaleDateString('fr-FR',{month:'long'})}</option>`).join('')}
                            </select>
                            <span style="margin-left:8px;color:#6b7280;">Année scolaire: <?= htmlspecialchars($annee_courante) ?></span>
                        </div>
                    </div>
                    <table id="tableau_detail">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Libellé</th>
                                <th>Compte</th>
                                <th>N° pièce</th>
                                <th>Bénéficiaire</th>
                                <th style="text-align:right">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml || '<tr><td colspan="6" style="text-align:center;color:#6b7280;">Aucune donnée</td></tr>'}
                        </tbody>
                    </table>
                    <div class="total" id="totalMois">Total: ${fmt(totalInitial)}</div>
                    ${soldeActuel !== null && soldeActuel !== undefined ? `<div class="total">Solde actuel: ${fmt(soldeActuel)}</div>` : ''}
                    <div class="actions">
                        <button class="btn" onclick="window.print()">Imprimer</button>
                    </div>
                </body></html>
            `;

            let w = window.open('', '_blank', 'width=900,height=1200');
            if (!w || w.closed || typeof w.closed === 'undefined') {
                const container = document.createElement('div');
                container.innerHTML = html;
                document.body.appendChild(container);
                initPrintDetails(document, kind, baseTitle, '<?= htmlspecialchars($annee_courante) ?>');
                return;
            }
            w.document.open();
            w.document.write(html);
            w.document.close();
            initPrintDetails(w.document, kind, baseTitle, '<?= htmlspecialchars($annee_courante) ?>');
        } catch (err) {
            console.error('[imprimerDetails] erreur:', err);
            alert('Impossible d’ouvrir les détails imprimables. Consultez la console pour plus d’informations.');
        }
    };

    // Initialisation du filtre et du titre
    window.initPrintDetails = function(doc, kind, baseTitle, anneeScolaire) {
        try {
            function parseMontantCell(cell) {
                const t = (cell && cell.textContent) ? cell.textContent.replace(/[^0-9\-,.]/g,'') : '0';
                const n = parseFloat(t.replace(/\./g,'').replace(',', '.'));
                return isNaN(n) ? 0 : n;
            }
            function fmt(val){
                try { return (val).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' FG'; }
                catch(e){ return val + ' FG'; }
            }
            function updateTitleForMois(mois) {
                const titleEl = doc.querySelector('.title');
                if (!titleEl) return;
                if (mois === 0) {
                    if (kind === 'caisse') {
                        titleEl.textContent = 'Détails des mouvements Caisse - ' + anneeScolaire;
                        return;
                    }
                    if (kind === 'banque') {
                        titleEl.textContent = 'Détails des mouvements Banque - ' + anneeScolaire;
                        return;
                    }
                }
                titleEl.textContent = baseTitle;
            }
            function filtrerParMois(mois) {
                const tbody = doc.querySelector('#tableau_detail tbody');
                if (!tbody) return;
                let total = 0;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.forEach(tr => {
                    const montantCell = tr.children[5];
                    const trMonth = parseInt(tr.getAttribute('data-month') || '0', 10);
                    const visible = (mois === 0) ? true : (trMonth === mois);
                    tr.style.display = visible ? '' : 'none';
                    if (visible) total += parseMontantCell(montantCell);
                });
                const totEl = doc.getElementById('totalMois');
                if (totEl) totEl.textContent = 'Total' + (mois ? ' mois' : '') + ': ' + fmt(total);
                updateTitleForMois(mois);
            }
            const select = doc.getElementById('filtreMois');
            if (select) {
                const m0 = parseInt(select.value,10);
                filtrerParMois(m0);
                select.addEventListener('change', function(){
                    const m = parseInt(this.value,10);
                    filtrerParMois(m);
                });
            }
        } catch(e) {
            console.warn('initPrintDetails exception', e);
        }
    };
})();

// Injection des champs Compte débité / Compte crédité dans la modale Trésorerie
function setupDualAccountFields() {
    try {
        const form = document.querySelector('#modalTresorerie form[action], #modalTresorerie form');
        if (!form) return;

        // Ne le faire qu'une seule fois
        if (form.__dualAccountsInstalled) return;

        // Trouver l'ancien select compte_id
        const selectCompte = form.querySelector('select[name="compte_id"]');
        if (!selectCompte) return;

        // Construire deux nouveaux selects
        const wrap = document.createElement('div');
        wrap.className = 'grid grid-cols-1 md:grid-cols-2 gap-4 mb-4';
        wrap.innerHTML = `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Compte débité <span class="text-red-500">*</span></label>
                <select name="compte_debit" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Sélectionner un compte</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Compte crédité <span class="text-red-500">*</span></label>
                <select name="compte_credit" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Sélectionner un compte</option>
                </select>
            </div>
        `;

        // Insérer le bloc juste avant le groupe qui contenait compte_id + moyen_paiement
        const group = selectCompte.closest('.grid');
        if (group && group.parentNode) {
            group.parentNode.insertBefore(wrap, group);
        } else {
            // sinon, insérer au début du formulaire
            form.insertBefore(wrap, form.firstChild);
        }

        // Peupler les options depuis window.ACCOUNTS
        const debitSel = wrap.querySelector('select[name="compte_debit"]');
        const creditSel = wrap.querySelector('select[name="compte_credit"]');
        const accounts = Array.isArray(window.ACCOUNTS) ? window.ACCOUNTS : [];
        accounts.forEach(acc => {
            const opt1 = document.createElement('option');
            opt1.value = acc.id;
            opt1.textContent = `${acc.numero_compte} - ${acc.nom_compte}`;
            debitSel.appendChild(opt1);

            const opt2 = document.createElement('option');
            opt2.value = acc.id;
            opt2.textContent = `${acc.numero_compte} - ${acc.nom_compte}`;
            creditSel.appendChild(opt2);
        });

        // Désactiver et masquer l'ancien select compte_id (conservé pour compat éventuelle)
        selectCompte.removeAttribute('required');
        selectCompte.name = '_compte_id_disabled';
        selectCompte.style.display = 'none';

        form.__dualAccountsInstalled = true;
    } catch (e) {
        console.warn('setupDualAccountFields error', e);
    }
}

// Initialisation: injecter les nouveaux champs à l'ouverture de la page
document.addEventListener('DOMContentLoaded', function () {
    setupDualAccountFields();
});
</script>
<script>
// Soumission AJAX de la modale Trésorerie vers stockage JSON côté serveur
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formTresorerieSimple');
    if (!form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(form);
        // Mapper les champs du formulaire existant vers l'API JSON simple
        const payload = new FormData();
        payload.set('date_op', fd.get('date_operation') || '');
        payload.set('type_op', fd.get('type_operation') || 'entree');
        payload.set('libelle', fd.get('libelle') || '');
        payload.set('montant', fd.get('montant') || '');
        payload.set('numero_piece', fd.get('numero_piece') || '');
        // notes optionnel (non présent dans le formulaire)
        payload.set('notes', '');

        try {
            const resp = await fetch('comptabilite.php', {
                method: 'POST',
                body: payload
            });
            const data = await resp.json();
            if (data && data.success) {
                alert('Opération enregistrée.');
                form.reset();
                fermerModalTresorerie && fermerModalTresorerie();
                // Optionnel: recharger pour rafraîchir l'historique si alimenté par DB
                // location.reload();
            } else {
                alert('Erreur: ' + (data && data.message ? data.message : 'Enregistrement impossible.'));
            }
        } catch (err) {
            console.error(err);
            alert('Une erreur technique est survenue.');
        }
    });
});
</script>
</body>
</html>