document.addEventListener('DOMContentLoaded', function () {
    const scoreFinalElement = document.getElementById('score-final');
    const thematiqueResultatElement = document.getElementById('thematique-resultat');
    const btnEnregistrerPdf = document.getElementById('btn-enregistrer-pdf');

    const urlParams = new URLSearchParams(window.location.search);
    const score = urlParams.get('score');
    const thematique = urlParams.get('thematique');
    const totalQuestions = urlParams.get('total') || 20; // Default à 20 si non fourni
    const codeUnique = urlParams.get('code');

    if (score !== null && thematique) {
        if (scoreFinalElement) {
            scoreFinalElement.textContent = `${score} / ${totalQuestions}`;
        }
        if (thematiqueResultatElement) {
            thematiqueResultatElement.textContent = decodeURIComponent(thematique);
        }

        // Enregistrer automatiquement le score dans la "base de données" (fichier JSON)
        if (codeUnique) {
            enregistrerScoreBD(codeUnique, score, decodeURIComponent(thematique), totalQuestions);
        } else {
            console.warn("Code unique manquant, le score ne sera pas enregistré côté serveur.");
        }

    } else {
        if (scoreFinalElement) {
            scoreFinalElement.textContent = "N/A";
        }
        if (thematiqueResultatElement) {
            thematiqueResultatElement.textContent = "N/A";
        }
        alert("Données de résultat manquantes. Impossible d'afficher le score.");
    }

    if (btnEnregistrerPdf) {
        btnEnregistrerPdf.addEventListener('click', function () {
            // Fonctionnalité PDF simplifiée : impression navigateur
            // Pour une génération PDF côté client plus avancée, on utiliserait des libs comme jsPDF ou html2pdf.js
            // Pour une génération PDF côté serveur, on enverrait les données à un script PHP qui utiliserait FPDF, TCPDF, etc.
            alert("Pour enregistrer en PDF, veuillez utiliser la fonction d'impression de votre navigateur (Ctrl+P ou Cmd+P) et choisissez 'Enregistrer en PDF' comme destination.");
            window.print();
        });
    }

    function enregistrerScoreBD(code, scoreObtenu, thematiqueJouee, totalQuiz) {
        fetch('php/enregistrer_score.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                codeUnique: code,
                score: parseInt(scoreObtenu), // Assurer que le score est un nombre
                thematique: thematiqueJouee,
                totalQuestions: parseInt(totalQuiz)
            }),
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Score enregistré avec succès côté serveur.');
                // On pourrait afficher un message discret à l'utilisateur ici
            } else {
                console.error('Erreur lors de l\'enregistrement du score côté serveur:', data.message);
                // Informer l'utilisateur si l'enregistrement échoue pourrait être utile
                // alert('Attention : Votre score n\'a pas pu être sauvegardé sur le serveur. ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erreur de communication pour enregistrer le score:', error);
            // alert('Attention : Une erreur de communication a empêché la sauvegarde de votre score sur le serveur.');
        });
    }
});
