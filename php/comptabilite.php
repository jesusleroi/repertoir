<?php
// Page Comptabilité - inclut une modale \"Nouvelle Opération de Trésorerie\"
// Les champs retirés du formulaire: moyen de paiement, catégorie, bénéficiaire.
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comptabilité</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Styles minimaux pour la modale (non intrusifs avec style.css existant) */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: #fff;
            max-width: 600px;
            width: 92%;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .modal header, .modal footer {
            padding: 12px 16px;
            background: #f6f6f6;
        }
        .modal header h3 {
            margin: 0;
        }
        .modal .modal-body {
            padding: 16px;
        }
        .modal .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .modal label {
            display: block;
            font-weight: 600;
            margin: 8px 0 4px;
        }
        .modal input[type="text"],
        .modal input[type="date"],
        .modal input[type="number"],
        .modal select,
        .modal textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font: inherit;
        }
        .modal textarea { min-height: 90px; resize: vertical; }
        .actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .btn {
            appearance: none;
            border: 1px solid #222;
            background: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn.primary {
            background: #222;
            color: #fff;
        }
        .btn.link {
            border: none;
            background: transparent;
            color: #444;
            text-decoration: underline;
        }
    </style>
</head>
<body>
<header>
    <h1>Comptabilité</h1>
</header>
<main>
    <section>
        <button id="btn-open-modal" class="btn primary">Nouvelle Opération de Trésorerie</button>
    </section>

    <!-- Modale: Nouvelle Opération de Trésorerie -->
    <div id="modal-op" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal">
            <header>
                <h3 id="modal-title">Nouvelle Opération de Trésorerie</h3>
            </header>
            <div class="modal-body">
                <form id="form-operation">
                    <!-- Champs conservés: Date, Type (Entrée/Sortie), Libellé, Montant, Notes -->
                    <div class="row">
                        <div>
                            <label for="date_op">Date</label>
                            <input type="date" id="date_op" name="date_op" required>
                        </div>
                        <div>
                            <label for="type_op">Type d'opération</label>
                            <select id="type_op" name="type_op" required>
                                <option value="">-- Sélectionner --</option>
                                <option value="entree">Entrée</option>
                                <option value="sortie">Sortie</option>
                            </select>
                        </div>
                    </div>

                    <label for="libelle">Libellé</label>
                    <input type="text" id="libelle" name="libelle" placeholder="Ex: Vente, Achat matériel..." required>

                    <label for="montant">Montant</label>
                    <input type="number" id="montant" name="montant" step="0.01" min="0" placeholder="0.00" required>

                    <label for="notes">Notes (optionnel)</label>
                    <textarea id="notes" name="notes" placeholder="Informations complémentaires..."></textarea>

                    <!-- Champs retirés explicitement:
                        - moyen de paiement
                        - catégorie
                        - bénéficiaire
                    -->
                </form>
            </div>
            <footer>
                <div class="actions">
                    <button type="button" class="btn link" id="btn-cancel">Annuler</button>
                    <button type="submit" form="form-operation" class="btn primary">Enregistrer</button>
                </div>
            </footer>
        </div>
    </div>
</main>

<footer>
    <p>&copy; 2023 Compétition en Ligne</p>
</footer>

<script>
    (function () {
        const backdrop = document.getElementById('modal-op');
        const openBtn = document.getElementById('btn-open-modal');
        const cancelBtn = document.getElementById('btn-cancel');
        const form = document.getElementById('form-operation');

        function openModal() {
            backdrop.classList.add('open');
        }
        function closeModal() {
            backdrop.classList.remove('open');
        }

        openBtn && openBtn.addEventListener('click', openModal);
        cancelBtn && cancelBtn.addEventListener('click', closeModal);
        backdrop && backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeModal();
        });

        form && form.addEventListener('submit', function (e) {
            e.preventDefault();
            // Placeholder de traitement — à brancher sur un endpoint PHP si nécessaire.
            const data = Object.fromEntries(new FormData(form).entries());
            console.log('Opération saisie:', data);
            alert('Opération enregistrée (simulation).');
            form.reset();
            closeModal();
        });
    })();
</script>
</body>
</html>