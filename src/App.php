<?php

namespace Geocrowd;

use PDO;

/** Services partagés par les routes. */
final class App
{
    public readonly PDO $pdo;
    public readonly Secrets $secrets;
    public readonly Images $images;
    public readonly Collections $collections;
    public readonly Points $points;
    public readonly Users $users;
    public readonly Edits $edits;
    public readonly ApiKeys $apiKeys;
    public readonly Front $front;
    public readonly Audit $audit;
    public readonly MailSettings $mailSettings;
    private ?Mailer $mailer = null;

    public function __construct(public readonly array $config, public readonly string $root)
    {
        $this->pdo = Db::connect($config['database']);
        // Dossier des images et de la clé de chiffrement ; peut être placé hors de la racine web.
        $dataDir = $config['data_dir'] ?? $root . '/data';
        $this->secrets = new Secrets($dataDir . '/secret.php');
        $this->images = new Images($dataDir . '/uploads');
        $this->points = new Points($this->pdo);
        $this->collections = new Collections($this->pdo, $root . '/collections', $this->images, $this->points);
        $this->users = new Users($this->pdo, $config['invitation_days'], $this->secrets);
        $this->edits = new Edits($this->pdo, $this->collections, $this->points, $this->images);
        $this->apiKeys = new ApiKeys($this->pdo);
        $this->front = new Front($this->pdo);
        $this->audit = new Audit($this->pdo);
        $this->mailSettings = new MailSettings($this->pdo, $this->secrets, $config['mail'] ?? null);
    }

    /** Envoi d'e-mails, construit à la demande (config.php ou réglages du back-office). */
    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer($this->mailSettings->forMailer());
    }

    /** Après un changement des réglages d'envoi. */
    public function resetMailer(): void
    {
        $this->mailer = null;
    }
}
