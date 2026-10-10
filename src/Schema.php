<?php

namespace Geocrowd;

/**
 * Validation des définitions de collection saisies dans l'éditeur de schéma.
 * Seules les options utiles à chaque type de champ sont conservées.
 */
final class Schema
{
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'multiselect', 'boolean', 'date', 'url', 'image', 'ref'];
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';
    private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';
    // Ajouté par l'API publique aux propriétés de chaque point.
    private const RESERVED = ['created_at'];
    private const MAX_FIELDS = 100;
    private const MAX_OPTIONS = 200;

    private array $errors = [];

    /**
     * Définition propre, ou erreur 422 avec le détail par chemin (« fields.2.options »).
     * $existing : version enregistrée (l'identifiant, et le nom et le type des champs
     * existants, ne changent plus) ; $ids : identifiants des collections existantes.
     */
    public static function normalize(mixed $input, ?array $existing, array $ids): array
    {
        $schema = new self();
        $input = is_array($input) ? $input : [];
        $id = $existing['id'] ?? $schema->id($input['id'] ?? null, $ids);

        $collection = [
            'id' => $id,
            'name' => $schema->text($input['name'] ?? null, 'name', 100),
            'description' => $schema->optionalText($input['description'] ?? null, 'description', 1000),
            'moderation' => (bool) ($input['moderation'] ?? true),
            'public_submission' => (bool) ($input['public_submission'] ?? true),
            'public_edit' => (bool) ($input['public_edit'] ?? $input['public_submission'] ?? true),
            'fields' => $schema->fields($input['fields'] ?? [], $existing, [...$ids, $id]),
        ];

        if ($schema->errors) {
            throw new HttpError(422, 'La collection contient des erreurs.', $schema->errors);
        }
        return $collection;
    }

    private function id(mixed $id, array $ids): string
    {
        $id = is_string($id) ? trim($id) : '';
        if (!preg_match(self::ID_PATTERN, $id)) {
            $this->errors['id'] = 'Lettres minuscules, chiffres, - et _ (50 caractères maximum).';
        } elseif ($id === 'new') {
            // Réservé : #/collections/new ouvre l'éditeur d'une nouvelle collection.
            $this->errors['id'] = 'Cet identifiant est réservé.';
        } elseif (in_array($id, $ids, true)) {
            $this->errors['id'] = 'Une collection utilise déjà cet identifiant.';
        }
        return $id;
    }

    private function fields(mixed $fields, ?array $existing, array $ids): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            $this->errors['fields'] = 'Liste de champs attendue.';
            return [];
        }
        if (count($fields) > self::MAX_FIELDS) {
            $this->errors['fields'] = self::MAX_FIELDS . ' champs maximum.';
        }

        $types = array_column($existing['fields'] ?? [], 'type', 'name');
        $names = [];
        $clean = [];
        foreach ($fields as $i => $field) {
            $field = is_array($field) ? $field : [];
            $path = "fields.$i";
            $name = is_string($field['name'] ?? null) ? trim($field['name']) : '';

            if (!preg_match(self::FIELD_PATTERN, $name) || in_array($name, self::RESERVED, true)) {
                $this->errors["$path.name"] = 'Lettres minuscules, chiffres et _, en commençant par une lettre (created_at est réservé).';
            } elseif (in_array($name, $names, true)) {
                $this->errors["$path.name"] = 'Deux champs ont ce nom.';
            }
            $names[] = $name;

            $type = $field['type'] ?? null;
            if (!in_array($type, self::FIELD_TYPES, true)) {
                $this->errors["$path.type"] = 'Type inconnu.';
                continue;
            }
            if (isset($types[$name]) && $types[$name] !== $type) {
                $this->errors["$path.type"] = 'Le type d\'un champ existant ne change pas : supprimez-le et créez-en un autre.';
            }

            $clean[] = ['name' => $name, 'label' => $this->text($field['label'] ?? null, "$path.label", 100), 'type' => $type]
                + ($type !== 'boolean' && !empty($field['required']) ? ['required' => true] : [])
                + $this->options($type, $field, $path, $ids);
        }
        return $clean;
    }

    /** Options propres au type du champ. */
    private function options(string $type, array $field, string $path, array $ids): array
    {
        switch ($type) {
            case 'text':
            case 'textarea':
            case 'url':
                return $this->integers($field, $path, ['maxLength' => [1, 10000]]);

            case 'number':
                $options = [];
                foreach (['min', 'max'] as $key) {
                    $value = $field[$key] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (is_int($value) || is_float($value)) {
                        $options[$key] = $value;
                    } else {
                        $this->errors["$path.$key"] = 'Nombre attendu.';
                    }
                }
                if (isset($options['min'], $options['max']) && $options['min'] > $options['max']) {
                    $this->errors["$path.max"] = 'Le maximum doit être supérieur au minimum.';
                }
                return $options;

            case 'select':
            case 'multiselect':
                $options = [];
                foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                    $option = is_scalar($option) ? trim((string) $option) : '';
                    if ($option !== '' && !in_array($option, $options, true)) {
                        $options[] = $option;
                    }
                }
                if (!$options) {
                    $this->errors["$path.options"] = 'Au moins un choix.';
                } elseif (count($options) > self::MAX_OPTIONS || max(array_map('mb_strlen', $options)) > 200) {
                    $this->errors["$path.options"] = self::MAX_OPTIONS . ' choix maximum, de 200 caractères chacun.';
                }
                return ['options' => $options];

            case 'image':
                return $this->integers($field, $path, ['max' => [1, 10], 'maxSize' => [1, 20]]);

            case 'ref':
                $target = $field['collection'] ?? null;
                if (!in_array($target, $ids, true)) {
                    $this->errors["$path.collection"] = 'Choisissez la collection des points désignés.';
                }
                return ['collection' => (string) $target];
        }
        return [];
    }

    /** Options entières facultatives, bornées : [nom => [min, max]]. */
    private function integers(array $field, string $path, array $bounds): array
    {
        $options = [];
        foreach ($bounds as $key => [$min, $max]) {
            $value = $field[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_int($value) && $value >= $min && $value <= $max) {
                $options[$key] = $value;
            } else {
                $this->errors["$path.$key"] = "Nombre entier entre $min et $max.";
            }
        }
        return $options;
    }

    private function optionalText(mixed $value, string $path, int $max): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (mb_strlen($value) > $max) {
            $this->errors[$path] = "$max caractères maximum.";
        }
        return $value;
    }

    private function text(mixed $value, string $path, int $max): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            $this->errors[$path] = "Entre 1 et $max caractères.";
        }
        return $value;
    }
}
