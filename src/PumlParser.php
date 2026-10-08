<?php
/*
 * LessHeadCMS - headless CMS generator (PlantUML -> SQLite -> REST API)
 * Copyright (C) 2026 Udo Butschinek
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace LessHeadCMS;

/**
 * Parses a PlantUML class diagram into a schema model (plain array, JSON-serializable).
 *
 * Type `visibility`: BOOLEAN column; at most one such field per entity. The API publicly returns only rows
 * with visibility = true (see Cms).
 *
 * Field syntax: `+ titel: string` = required field (NOT NULL), `+ untertitel: string?` = optional.
 * Foreign keys are independent of this: "1" = required, "0..1" = optional.
 *
 * `{title}` marker (`+ status: string {title}`): determines which field(s) serve as the display label (FK
 * dropdowns, n:n lists) instead of the automatic guessing heuristic (see Cms::titleField()). Several `{title}` fields
 * of a class are combined in diagram order. Only allowed for string/int/date/decimal (see
 * TITLE_ALLOWED_TYPES) - for any other type a schema error, not silently ignored (see parseClass()).
 *
 * FK column name: with a label `<label>_<targettable>_id` (see normalizeLabel), without a label as before
 * `<targettable>_id`. If two relationships of a class collide on the same resulting column name
 * (e.g. two unlabelled relationships to the same target table, or the same label to the same target table),
 * the parser aborts instead of silently overwriting (see addForeignKey).
 *
 * `{cascade}` marker at the end of an n:1 relation (`Bestellposition "n" --> "1" Bestellung : gehört zu {cascade}`):
 * deleting the referenced row deletes the dependent rows along with it (Cms::delete). Without the marker, restrict applies
 * (deleting is rejected with 409 as long as dependent rows exist). The marker is not part of the label, so the
 * column name stays the same as without it. On n:n it is a schema error (links in the
 * link table are always deleted along anyway), as is any other `{...}` marker on a relation.
 *
 * Minimum count on the n side of an n:1 relation (`Position "1..*" --> "1" Rechnung`, likewise `1..n`): "every invoice
 * has at least one line item". This is a soft rule - the tables are the same as with `n` (cannot be enforced on
 * creation: the invoice always exists before its line items). The model contains foreign_key.min_required = true; Cms
 * uses it to protect the last remaining line item from deletion (409) and reports invoices without a line item
 * (`_min_warnings`). On n:n a minimum count remains a schema error.
 *
 * `{filter_by:field}` or `{filter_by:sourcefield=targetfield}` on an n:1, 1:1 or n:n relation
 * (`Artikel "n" -- "n" Tag : hat {filter_by:sprache}`): filtered selection in the form, see resolveFilter(). The model
 * contains foreign_key.filter_by or many_to_many[].filter_by = ['field', 'target_field'] - only with the marker.
 *
 * 1:1 relations (both sides "1" or "0..1", e.g. `Profil "1" --> "1" Nutzer`): FK column as for n:1 in the
 * class named first (with an arrow written backwards `B <-- A` in A, the arrow points at the target), required according
 * to the multiplicity on the target side (as for n:1). The column is implicitly unique (foreign_key.one_to_one = true, own
 * UNIQUE constraint, independent of a {unique} group). Schema error: 1:1 self-reference with a required target side.
 *
 * `{label}` marker at the end of an n:n relation (`Mitglied "n" -- "n" Kurs : interessiert an {label}`): the
 * UI shows the relationship label instead of the target table name (many_to_many[].show_label = true). Without the marker
 * the label stays pure diagram documentation (e.g. the usual UML "hat"). Schema errors: `{label}` without a label before it,
 * `{label}` on an n:1 relation (the label is always displayed there anyway) and several markers on one line.
 *
 * Several n:n relationships of a class to the same target table (`Artikel "n" -- "n" Tag : Haupttags` and
 * `... : Zusatztags`): labelled relationships of such a pair are then named `<entity>_<label>_<target>` (link table)
 * and `<label>_<target>_ids` (API list), label normalized as for the FK column name; unlabelled ones keep the
 * default name `<entity>_<target>` / `<target>_ids`. A single n:n relationship is always named as before, even with a
 * label (see manyNames). Two unlabelled or identically labelled relationships to the same target are a schema error.
 * Independently of this, `{label}` only affects the display label.
 *
 * `{unique}` marker on fields (`+ nr: string {unique}`, combinable with `{title}`) and n:1 relations
 * (`Bestellposition "n" --> "1" Variante : {unique}`, combinable with `{cascade}`): all columns of a class marked this
 * way together form ONE uniqueness rule (unique_fields; with only one column, simple uniqueness). Several
 * independent groups per class deliberately do not exist. Schema errors: {unique} on n:n relations and on the field `id`.
 *
 * n:n self-reference (`Nutzer "n" -- "n" Nutzer : folgt`): directed by default - "A follows B" and "B follows A" are two
 * independent rows, the API list only shows the outgoing direction. With `{symmetric}` (`... : befreundet mit {symmetric}`)
 * undirected: one row per pair, canonically with the smaller ID in own_column, read from both sides (Cms). Names
 * (label required): link table `<entity>_<label>_<entity>`, columns `<entity>_id` (the row whose list it is) and
 * `<label>_<entity>_id` (the linked row, built like an FK column name), API list `<label>_<entity>_ids`.
 * Schema errors: self-reference without a label, `{symmetric}` on anything but an n:n self-reference. `{symmetric}` can
 * be combined with `{label}`. Linking a row to itself is a data error (422 in Cms), not a schema error.
 *
 * `package "Name" { ... }` blocks around classes: purely a display grouping in the sidebar (entities[].package), no
 * structural separation - class names stay unique project-wide, table names do not change. Classes outside
 * any package have package = null. Nested packages are a schema error.
 *
 * `enum Name { ... }` blocks (one value per line, see extractEnums()): named value range that fields of any
 * class reference as a type via the name (`+ status: Status`, optionally `Status?`; the type name is case-insensitive
 * like the base types). The field gets type = 'enum', stored as VARCHAR with a CHECK constraint
 * on the values (SqlGenerator), `{title}`/`{unique}` are allowed. The order in the block is the display order.
 * Schema errors: invalid name, name equal to a base type or a class, duplicate enum name, block without values,
 * invalid or duplicate value (case-insensitive). An enum that no field uses is not an
 * error (it still appears in model['enums']).
 *
 * Inheritance as a pure field copy mechanism (see resolveInheritance()): `abstract class Basis { ... }` is a
 * template without a table of its own, `class B extends Basis { ... }` first gets all fields of the base (in its
 * order, including `?`/{title}/{unique}), then its own - exactly as if they were written directly in B. No shared
 * table, no polymorphism; to the outside (_schema, frontend) B is a perfectly normal entity. Nested
 * (`abstract class Mitte extends Basis`) is copied recursively. Schema errors: extends on a non-abstract or
 * unknown class, several base classes, cyclic inheritance, a field of the subclass with the same name as an inherited one,
 * a relation from/to an abstract class (it has no table; relations are not inherited), an extends line that cannot be
 * read as `class B extends A {`. An abstract class without a subclass is not an error.
 *
 * Field type `media` (`+ galerie: media`, optionally `media?`): reference to files of the global media library (system
 * table media, see Media), always as an ordered list - "just one image" is a list with one element. No column in the
 * table of the class but (as for n:n) a separate link table `<table>_<field>_media` with `<table>_id`, `media_id` and
 * `position` (order). Without `?` at least one medium must be linked (Cms), with `?` the list may also be
 * empty. The field ends up in the model under entities[].media instead of under fields (see extractMediaFields()), but
 * until then it is checked for name collisions and inherited like a normal field. Schema errors: `{title}`/`{unique}` on a
 * media field, a link table that collides with a class, another link table or a reserved name,
 * an enum named `media`.
 *
 * `{renamed_from:Altes Label}` at the end of a relation (`A "n" --> "1" B : Rubrik {renamed_from:Kategorie-Zuordnung}`,
 * combinable with all other markers; empty = previously without a label): likewise only for SchemaMigration, stored as
 * foreign_key.renamed_from or many_to_many[].renamed_from (missing without the marker).
 *
 * `{renamed_from:AlterName}` on a field (`+ neu: string {renamed_from:alt}`) or a class (`class Neu {renamed_from:Alt}
 * {`, with extends after it: `class B extends A {renamed_from:Alt} {`): only for the schema editing of a populated
 * installation (SchemaMigration) - the data of the old name is kept under the new one. The parser only stores the
 * marker (field/entity 'renamed_from', missing without the marker) and checks nothing against an earlier model; that is
 * done by SchemaMigration. Schema errors: on the field id, on an abstract class, a class line with the marker but without "{".
 *
 * Workflow field (`+ state: ArticleWorkflow`): a type name ending in `Workflow` (and not being an enum) references a
 * workflow of the active workflow file (WorkflowParser, System -> Workflows) - parse() receives its definitions as the
 * second parameter. At database level the field is a required enum over the states (type 'enum', enum = name of the
 * workflow, CHECK constraint), additionally carries 'workflow' => name and follows rules of its own (Cms: changes only via
 * transitions). Schema errors: no active workflow configured (null), workflow not defined, name not equal to
 * `<ClassName>Workflow`, more than one workflow field per class, `?`/{title}/{unique}, in an abstract class,
 * `action=set_visibility` without a visibility field of the class, an enum of the same name. Workflows without a matching
 * class are not an error (like an unused enum). In the model: 'workflows' (all definitions of the active file) only if a
 * workflow file is active - without one the model is exactly the previous one.
 *
 * Unsupported PlantUML constructs are schema errors instead of being silently discarded: classes with `<<Stereotype>>`
 * or `implements`, `interface` blocks, class lines without a body or with an unrecognizable body (the class would
 * otherwise be dropped completely), a minimum count (`1..*`) on n:n relationships, an `id` field with a type other than
 * `int` or with `?`, and a field named like a generated
 * n:n list.
 *
 * Model:
 * [
 *   'entities' => [ '<table>' => [
 *       'name' => 'Artikel', 'table' => 'artikel',
 *       'fields' => [ ['name','type','sql_type','primary','required','foreign_key'=>null|['table','column','label','on_delete','one_to_one','min_required']]
 *           (on_delete: 'restrict' | 'cascade'; in models stored before this option the key is missing = restrict;
 *           one_to_one: 1:1 relationship, missing in older models = false;
 *           min_required: only present (true) for "1..*"/"1..n" on the n side, see above;
 *           only for type 'enum' additionally 'enum' => 'Status' (name as declared) and 'enum_values' => ['OFFEN', ...]) ],
 *       'many_to_many' => [ ['name','table','junction','own_column','other_column','label','show_label','symmetric'] ]
 *           (show_label: {label} marker; symmetric: {symmetric} self-reference; in older stored models
 *           the keys are missing = false),
 *       'title_fields' => ['status', ...] (order = diagram order; empty = no {title} markers, Cms
 *           then guesses by heuristic),
 *       'unique_fields' => ['bestellung_id', 'variante_id'] ({unique} group: fields in diagram order, then
 *           FK columns in relation order; empty = no rule; in older stored models the key is missing),
 *       'package' => 'Katalog' | null (enclosing package block; sidebar grouping only, see
 *           parsePackageLine(); in older stored models the key is missing = null),
 *       'extends' => 'Basisinhalt' | null (direct base class, for documentation only - the fields are already copied;
 *           not evaluated anywhere and not exposed; in older models the key is missing),
 *       'media' => [ ['name' => 'galerie', 'junction' => 'kurs_galerie_media', 'own_column' => 'kurs_id', 'required' => true] ]
 *           (media fields in diagram order; in older stored models the key is missing = none),
 *   ] ],
 *   'junctions' => [ ['table','left','right','left_column','right_column','label','symmetric'] ],
 *   'enums' => [ 'Status' => ['OFFEN', 'VERSANDT', ...] ] (all declared ones, unused ones too; in older models the key is missing),
 *   'abstract' => ['Basisinhalt', ...] (names of the abstract classes, without a table; in older models the key is missing),
 * ]
 */
final class PumlParser
{
    /** puml type => SQLite column type */
    public const TYPE_MAP = [
        'string'   => 'VARCHAR(255)',
        'text'     => 'TEXT',
        'richtext' => 'TEXT',
        'int'      => 'INTEGER',
        // For floating point numbers (e.g. amounts of money); SQLite has no native DECIMAL, REAL is the common choice.
        // On the precision question for amounts of money (REAL vs. storing integer cents) see README, section "Types".
        'decimal'  => 'REAL',
        'bool'     => 'BOOLEAN',
        'date'     => 'DATE',
        // visibility (published/draft): stored like bool, but stays "visibility" in the schema
        'visibility' => 'BOOLEAN',
    ];

    /**
     * Types for which a `{title}` marker makes sense (short values that are readable on their own, plus every enum type).
     * `text`/`richtext` (long, possibly Markdown), `bool`/`visibility` (no meaningful display text) are deliberately
     * excluded - see parseClass().
     */
    private const TITLE_ALLOWED_TYPES = ['string', 'int', 'date', 'decimal', 'enum'];

    /** Field type for references to the media library (not a column but a link table, see class comment) */
    public const MEDIA_TYPE = 'media';

    /**
     * Tables and routes the CMS occupies itself: users table, /api/login, /api/logout, /api/me and the
     * UI routes of the system section (#/users, #/testdata - see frontend/src/components/systemEntries.jsx),
     * plus the system tables login_attempts (rate limit, see Auth) and user_column_prefs (column selection per user,
     * see ColumnPrefs), the media library (media, media_tags, media_tag_links, see Media; "media" is also the
     * UI route #/media) and the stable IDs/the diagram layout of the schema editor (schema_ids, schema_layout,
     * see SchemaIds), multilingual support (languages, translations, user_language_prefs, see Languages) as well as roles and
     * permissions (roles, groups, permissions, group_roles, user_roles, user_groups, see Permissions) and the tasks of the
     * workflows (lh_task, see Workflows; deliberately with a prefix - "tasks" stays available as a class name) and the
     * API keys (api_keys, see ApiKeys).
     */
    public const RESERVED = [
        'users', 'login', 'logout', 'me', 'testdata', 'login_attempts', 'user_column_prefs', 'media', 'media_tags',
        'media_tag_links', 'schema_ids', 'schema_layout', 'languages', 'translations', 'user_language_prefs',
        'roles', 'groups', 'permissions', 'group_roles', 'user_roles', 'user_groups', 'lh_task', 'api_keys',
    ];

    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const NN_LABEL_HINT = 'Bitte jede n:n-Beziehung zur selben Zieltabelle mit einem eigenen, eindeutigen Label '
        . 'versehen (z. B. Artikel "n" -- "n" Tag : Haupttags).';

    /** Format identifier of the model JSON (see modelJson()) */
    public const MODEL_FORMAT = 'lessheadcms-model';
    public const MODEL_VERSION = 1;

    /**
     * @param array<string,array>|null $workflows definitions of the active workflow file (WorkflowParser::parse()); null =
     *        no workflow file active (a workflow field is then a schema error)
     */
    public static function parse(string $source, ?array $workflows = null): array
    {
        return self::analyze($source, $workflows)['model'];
    }

    /**
     * Model JSON (intermediate format for the visual schema editor, independent of the diagram library): the same
     * evaluation as parse() - same schema errors -, only as an additional output form. Unlike the internal model,
     * it represents the diagram the way it is written: abstract classes with their fields, per class only its own
     * fields (inherited ones via extends), relationships as a separate list with the multiplicities instead of as FK
     * columns. From it the diagram can be written back semantically identical (PumlExporter). Comments, formatting and
     * purely decorative statements are lost; the latter (title, skinparam, note, colours, ...) and every otherwise
     * ignored line appear in 'warnings'.
     *
     * Structure (every object with 'id'; in this version derived from the name, so only reliable within one parse
     * run - it changes on renaming):
     * [
     *   'format' => 'lessheadcms-model', 'version' => 1,
     *   'entities' => [ ['id' => 'entity:kurs', 'name', 'table', 'package' => string|null, 'abstract' => bool,
     *       'extends' => 'Basis'|null (name as declared), 'renamed_from' => string|null,
     *       'fields' => [ ['id' => 'field:kurs.titel', 'name', 'type' (base type or 'enum'), 'enum_name' => string|null,
     *           'sql_type', 'required', 'primary', 'title', 'unique', 'renamed_from' => string|null] ] (own fields without
     *           FK columns and media; the id only for classes without extends - for subclasses it comes from the base),
     *       'title_fields' => [...], 'unique_fields' => [...] (own fields; {unique} on relations is stored there),
     *       'media' => [ ['id' => 'media:kurs.galerie', 'name', 'required', 'renamed_from'] ] ] ],
     *   'relations' => [ ['id', 'kind' => 'n1'|'one_to_one'|'nn', 'from_entity', 'to_entity', 'from_multiplicity',
     *       'to_multiplicity', 'label', 'show_label', 'cascade', 'unique', 'symmetric', 'own_column' (FK column in
     *       from_entity, null for nn), 'junction' (nn only), 'renamed_from' => string|null,
     *       'filter_by' => 'sprache'|'sprache=lang'|null] ]
     *       (direction normalized: for n1/one_to_one from_entity is the class with the FK column, for nn the one
     *       named first; the multiplicities stay on their side as written),
     *   'enums' => [ ['id' => 'enum:status', 'name', 'values' => [...]] ],
     *   'warnings' => ["Zeile 3: 'skinparam' wird nicht übernommen", ...],
     * ]
     *
     * @throws SchemaException as parse()
     */
    public static function modelJson(string $source, ?array $workflows = null): array
    {
        $a = self::analyze($source, $workflows);
        $model = $a['model'];
        $entities = [];
        foreach ($a['classes'] as $table => $c) {
            $own = array_values(array_filter($c['fields'], function ($f) use ($c) {
                return !($c['extends'] !== null && $f['primary']);
            }));
            $fields = [];
            $media = [];
            foreach ($own as $f) {
                $fid = $table . '.' . strtolower($f['name']);
                if ($f['type'] === self::MEDIA_TYPE) {
                    $media[] = [
                        'id' => 'media:' . $fid, 'name' => $f['name'], 'required' => $f['required'],
                        'renamed_from' => $f['renamed_from'] ?? null,
                    ];
                    continue;
                }
                $fields[] = [
                    'id' => 'field:' . $fid, 'name' => $f['name'], 'type' => $f['type'], 'enum_name' => $f['enum'] ?? null,
                    'sql_type' => $f['sql_type'], 'required' => $f['required'], 'primary' => $f['primary'],
                    'title' => in_array($f['name'], $c['title_fields'], true),
                    'unique' => in_array($f['name'], $c['unique_fields'], true),
                    'renamed_from' => $f['renamed_from'] ?? null,
                ] + (isset($f['workflow']) ? ['workflow' => true] : []); // workflow field: enum_name = name of the workflow
            }
            $entities[] = [
                'id' => 'entity:' . $table, 'name' => $c['name'], 'table' => $table,
                'package' => $c['abstract'] ? ($a['abstract_packages'][$table] ?? null) : $model['entities'][$table]['package'],
                'abstract' => $c['abstract'],
                'extends' => $c['extends'] !== null ? $a['classes'][strtolower($c['extends'])]['name'] : null,
                'renamed_from' => $c['renamed_from'] ?? null,
                'fields' => $fields, 'title_fields' => $c['title_fields'], 'unique_fields' => $c['unique_fields'],
                'media' => $media,
            ];
        }
        $relations = [];
        foreach ($a['relations'] as $r) {
            $from = $model['entities'][$r['from']];
            $junction = null;
            if ($r['kind'] === 'nn') {
                $junction = $from['many_to_many'][$r['index']]['junction']; // only final now (see applyRelation)
            }
            $relations[] = [
                'id' => 'relation:' . ($junction ?? $r['from'] . '.' . $r['column']), 'kind' => $r['kind'],
                'from_entity' => $from['name'], 'to_entity' => $model['entities'][$r['to']]['name'],
                'from_multiplicity' => $r['from_multiplicity'], 'to_multiplicity' => $r['to_multiplicity'],
                'label' => $r['label'], 'show_label' => $r['show_label'], 'cascade' => $r['cascade'],
                'unique' => $r['unique'], 'symmetric' => $r['symmetric'],
                'own_column' => $r['kind'] === 'nn' ? null : $r['column'], 'junction' => $junction,
                'renamed_from' => $r['renamed_from'], 'filter_by' => $r['filter_by'],
            ];
        }
        $enums = [];
        foreach ($model['enums'] as $name => $values) {
            // states of a used workflow: like an enum (translations, display), but not part of the diagram text
            $enums[] = ['id' => 'enum:' . strtolower($name), 'name' => $name, 'values' => $values]
                + (isset($a['workflow_enums'][$name]) ? ['workflow' => true] : []);
        }
        return [
            'format' => self::MODEL_FORMAT, 'version' => self::MODEL_VERSION,
            'entities' => $entities, 'relations' => $relations, 'enums' => $enums, 'warnings' => $a['warnings'],
        ];
    }

    /**
     * Shared evaluation for parse() and modelJson(): 'model' (internal model, see class comment), plus 'classes'
     * (all classes before inheritance resolution, abstract ones too, with their own fields), 'relations' (per relation line,
     * see applyRelation()), 'abstract_packages' (table => package of the abstract classes) and 'warnings'.
     */
    private static function analyze(string $source, ?array $workflows = null): array
    {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        // Remove block comments /' ... '/; afterwards $lineOf maps every line to its original line number
        // (for warnings), the text itself is shortened exactly as before
        $lineOf = [1];
        $stripped = '';
        $pos = 0;
        $line = 1;
        preg_match_all("#/'.*?'/#s", $source, $comments, PREG_OFFSET_CAPTURE);
        foreach (array_merge($comments[0], [[null, strlen($source)]]) as [$comment, $offset]) {
            $segment = substr($source, $pos, $offset - $pos);
            for ($k = substr_count($segment, "\n"); $k > 0; $k--) {
                $lineOf[] = ++$line;
            }
            $stripped .= $segment;
            if ($comment !== null) {
                $line += substr_count($comment, "\n");
                $pos = $offset + strlen($comment);
            }
        }
        $source = $stripped;
        $warnings = []; // [line number, text], sorted by line at the end
        $warn = function (int $index, string $text) use (&$warnings, $lineOf): void {
            $warnings[] = [$lineOf[$index] ?? $index + 1, $text];
        };

        $enums = [];
        $source = self::extractEnums($source, $enums, $warn);
        foreach (array_keys($enums) as $enum) {
            foreach (array_keys($workflows ?? []) as $wf) {
                if (strcasecmp($enum, $wf) === 0) {
                    throw new SchemaException("enum '$enum' heißt wie der Workflow '$wf' der aktiven Workflow-Datei. Bitte das "
                        . 'Enum anders benennen.');
                }
            }
        }

        $entities = [];

        // 1. Classes
        // The closing "}" of the class must stand alone (surrounded only by whitespace) on its own line -
        // not simply "first } in the rest of the text", because a {title} marker (see below) sits in the middle of a
        // field line and contains a "}" itself, which would otherwise be misread as the end of the class.
        // Every class is replaced in the rest of the text by a placeholder "\x01<table>\x01" so that step 2
        // knows which package block it was in.
        // Optionally "abstract" before it (template without a table) and "extends <Base>" after it (see resolveInheritance()).
        // The extends part is captured broadly here (everything up to "{") so that multiple bases are detected instead of swallowed.
        // Class line with {renamed_from:…} but without an opening "{" after it: otherwise the class pattern would take the
        // marker's brace for the class body and report an incomprehensible field line
        // interface blocks are not supported and would otherwise disappear silently (only a relation to it would then report an
        // "unknown class") - so report right at the interface line. As for enum, the name ends before ":" so that a
        // field "interface : string" without a visibility sign in a class body does not count as an interface.
        foreach (explode("\n", $source) as $line) {
            if (preg_match('/^\s*interface\s+([^\s{:<]+)/i', $line, $if)) {
                throw new SchemaException(
                    "Interfaces ('interface') werden aktuell nicht unterstützt ('{$if[1]}'). Bitte stattdessen eine normale "
                    . "Klasse ('class {$if[1]} { ... }') verwenden."
                );
            }
            if (preg_match('/^\s*(?:abstract\s+)?class\s+[^{]*\{renamed_from:[^}]*\}\s*$/i', $line)) {
                throw new SchemaException(
                    'Klassenzeile mit {renamed_from:…} nicht verständlich (erwartet: class Neu {renamed_from:Alt} { mit "{" auf '
                    . 'derselben Zeile): ' . trim($line)
                );
            }
        }
        // Optionally preceded by "{renamed_from:AlterName}" (schema editing: the table of class AlterName is renamed).
        // A colour before "{" (`class A #FFEECC {`, purely decorative) is recognized and only reported as a warning.
        $classPattern = '/^[ \t]*(abstract[ \t]+)?class[ \t]+([^\s{]+)((?:[ \t]+extends\b[^{\n]*?)?)((?:[ \t]+#\w+)?)'
            . '(?:[ \t]*\{renamed_from:([A-Za-z_][A-Za-z0-9_]*)\})?[ \t]*\{(.*?)\n[ \t]*\}/ms';
        // line numbers of the class heads (same matches in the same order as preg_replace_callback below)
        preg_match_all($classPattern, $source, $heads, PREG_OFFSET_CAPTURE);
        $headLines = array_map(function ($h) use ($source) {
            return substr_count($source, "\n", 0, $h[1]);
        }, $heads[0]);
        $classes = []; // table => parsed class, abstract ones too, with 'abstract' and 'extends'
        $rest = preg_replace_callback($classPattern, function (array $match) use (&$classes, $enums, $headLines, $warn, $workflows): string {
            $head = $headLines[count($classes)] ?? 0;
            if ($match[4] !== '') {
                $warn($head, "Farbangabe '" . trim($match[4]) . "' an Klasse '{$match[2]}' wird nicht übernommen");
            }
            $entity = self::parseClass($match[2], $match[6], $enums, function (int $i, string $text) use ($warn, $head) {
                $warn($head + $i, $text); // line 0 of the body is the rest of the head line after "{"
            }, $workflows);
            if (isset($classes[$entity['table']])) {
                throw new SchemaException("Klasse '{$match[2]}' ist doppelt definiert.");
            }
            $entity['extends'] = self::parseExtends($match[2], $match[3]);
            $entity['abstract'] = trim($match[1]) !== '';
            if ($entity['abstract'] && array_filter($entity['fields'], function ($f) {
                return isset($f['workflow']);
            })) {
                throw new SchemaException("Abstrakte Klasse '{$match[2]}' kann kein Workflow-Feld haben (ein Workflow gehört zu "
                    . 'genau einer Klasse mit eigener Tabelle).');
            }
            if (($match[5] ?? '') !== '') {
                if ($entity['abstract']) {
                    throw new SchemaException(
                        "Abstrakte Klasse '{$match[2]}' kann nicht mit {renamed_from:…} markiert werden (sie hat keine Tabelle)."
                    );
                }
                $entity['renamed_from'] = $match[5];
            }
            $classes[$entity['table']] = $entity;
            // Placeholder for abstract classes too (modelJson() needs their package); the line breaks of the class
            // are kept so that the line numbers in the rest of the text stay correct
            return "\x01{$entity['table']}\x01" . str_repeat("\n", substr_count($match[0], "\n"));
        }, $source);
        // An extends line the class pattern did not capture (e.g. without "{" on the same line) would otherwise
        // disappear silently - as would a class with <<Stereotype>> or implements (between name and "{" the class pattern
        // only allows extends, a colour and {renamed_from:…}; so only uncaptured class lines remain here)
        foreach (explode("\n", $rest) as $line) {
            if (preg_match('/^\s*(?:abstract\s+)?class\s+([^\s{<]+)\s*(<<[^>]*>>)/i', $line, $st)) {
                throw new SchemaException(
                    "Stereotypen ('<<...>>') werden aktuell nicht unterstützt ('{$st[1]} {$st[2]}'). Bitte den Stereotyp entfernen."
                );
            }
            if (preg_match('/^\s*(?:abstract\s+)?class\s+(\S+)\s+implements\b\s*([^{]*)/i', $line, $im)) {
                $iface = trim($im[2]);
                throw new SchemaException(
                    "'implements' wird aktuell nicht unterstützt ('{$im[1]} implements $iface'). Bitte die Felder von '$iface' "
                    . "direkt in '{$im[1]}' übernehmen (Duplizieren) oder 'extends' mit einer 'abstract class' verwenden."
                );
            }
            if (preg_match('/^\s*(?:abstract\s+)?class\s+\S+.*\{renamed_from/i', $line)) {
                throw new SchemaException(
                    'Klassenzeile mit {renamed_from:…} nicht verständlich (erwartet: class Neu {renamed_from:Alt} { mit "{" auf '
                    . 'derselben Zeile): ' . trim($line)
                );
            }
            if (preg_match('/^\s*(?:abstract\s+)?class\s+\S+.*\sextends\s/i', $line)) {
                throw new SchemaException(
                    'Klassenzeile mit extends nicht verständlich (erwartet: class B extends A { mit "{" auf derselben Zeile '
                    . 'und "}" allein auf einer eigenen Zeile): ' . trim($line)
                );
            }
            // Every remaining class line was not captured by the class pattern - the class would otherwise be dropped silently:
            // without a body ("class X" alone) or with a body that is not laid out as expected ("class X {}" on one line,
            // closing "}" missing or not alone on its line)
            if (preg_match('/^\s*(?:abstract\s+)?class\s+(\S+)\s*$/i', $line, $nb)) {
                throw new SchemaException(
                    "Klassenzeile '{$nb[1]}' ohne Körper ('{ ... }') nicht verständlich. Bitte Felder in geschweiften Klammern "
                    . 'ergänzen.'
                );
            }
            if (preg_match('/^\s*(?:abstract\s+)?class\s+\S/i', $line)) {
                throw new SchemaException(
                    'Klassenzeile nicht verständlich (erwartet: class X { mit "{" auf derselben Zeile und "}" allein auf einer '
                    . 'eigenen Zeile): ' . trim($line)
                );
            }
        }
        $entities = self::resolveInheritance($classes);
        $abstract = [];
        foreach ($classes as $c) {
            if ($c['abstract']) {
                $abstract[strtolower($c['name'])] = $c['name'];
            }
        }
        if (!$entities) {
            throw new SchemaException(
                'Keine Klassen in der .puml-Datei gefunden.' . ($abstract ? ' (Abstrakte Klassen bekommen keine eigene Tabelle.)' : '')
            );
        }
        foreach (array_keys($enums) as $enum) {
            if (isset($entities[strtolower($enum)]) || isset($abstract[strtolower($enum)])) {
                throw new SchemaException(
                    "enum '$enum' heißt wie eine Klasse. Bitte einen der beiden umbenennen."
                );
            }
        }

        // 2. package blocks and relations
        $junctions = [];
        $relations = []; // per relation line for modelJson(), see applyRelation()
        $abstractPackages = [];
        $blocks = []; // open {...} blocks outside of classes: package name or null (e.g. namespace, together)
        $skipUntil = null; // multi-line decorative block (note, title, legend, skinparam {...}): pattern of its end
        $noteAliases = []; // note "..." as N1 -> connecting lines "N1 .. Class" are likewise just decoration
        foreach (explode("\n", $rest) as $index => $line) {
            if (preg_match_all('/\x01([^\x01]*)\x01/', $line, $placeholders)) {
                foreach ($placeholders[1] as $table) {
                    if (isset($entities[$table])) {
                        $entities[$table]['package'] = self::currentPackage($blocks);
                    } else {
                        $abstractPackages[$table] = self::currentPackage($blocks); // abstract class
                    }
                }
                $line = preg_replace('/\x01[^\x01]*\x01/', '', $line);
            }
            $line = trim($line);
            if ($skipUntil !== null) {
                if (preg_match($skipUntil, $line)) {
                    $skipUntil = null;
                }
                continue;
            }
            if ($line === '' || $line[0] === "'") {
                continue;
            }
            if (self::parsePackageLine($line, $blocks)) {
                if (preg_match('/\s#\w+/', (string) preg_replace('/"[^"]*"/', '""', $line), $color)) {
                    $warn($index, "Farbangabe '" . trim($color[0]) . "' an package wird nicht übernommen");
                }
                continue;
            }
            if ($line === '}') {
                array_pop($blocks); // surplus "}" have always been ignored
                continue;
            }
            // optional markers at the end of the line, with or without a label before them: "... B : label {cascade}" /
            // "... B {cascade}" / "... B : label {cascade} {unique}"
            // {renamed_from:Altes Label} (schema editing) may contain spaces/umlauts/hyphens like a label
            $relPattern = '/^(\S+)\s+"([^"]*)"\s+(\S+)\s+"([^"]*)"\s+([^\s{]+)\s*(?::\s*(.*?))?\s*'
                . '((?:\{(?:\w*|(?i:renamed_from|filter_by):[^{}]*)\}\s*)*)$/';
            // remove a colour in the arrow ("-[#red]->", purely decorative)
            $arrow = preg_match($relPattern, $line, $r) ? (string) preg_replace('/\[#\w+(?:[,;]#?\w+)*\]/', '', $r[3]) : '';
            $isRelation = $r && preg_match('/[-.]{2}/', $arrow);
            // Purely decorative PlantUML statements: without meaning for the schema, they are lost in the model JSON -> warning.
            // Only if the line is not a relation (a class may e.g. be called "Title")
            $decor = $isRelation ? null : self::decoration($line, $noteAliases);
            if ($decor !== null) {
                $warn($index, "'{$decor[0]}' wird nicht übernommen");
                $skipUntil = $decor[1];
                continue;
            }
            if (substr($line, -1) === '{') {
                $blocks[] = null; // other block (namespace, together, ...), its "}" must not close a package
            }
            if ($isRelation) {
                if ($arrow !== $r[3]) {
                    $warn($index, "Farbangabe im Pfeil '{$r[3]}' wird nicht übernommen");
                }
                $marker = null; // {cascade} or {label}; null = none
                $flags = ['unique' => false, 'symmetric' => false];
                $renamedFrom = null; // {renamed_from:Altes Label}: only for SchemaMigration, without meaning at the first bootstrap
                $filterBy = null; // {filter_by:field} or {filter_by:sourcefield=targetfield}: filtered selection, see resolveFilter()
                $multi = "Nur eine Markierung pro Relation möglich ({cascade} oder {label}, zusätzlich höchstens "
                    . "einmal {unique}, {symmetric}, {filter_by:…} bzw. {renamed_from:…}): $line";
                preg_match_all('/\{(\w*)(?::([^{}]*))?\}/', $r[7] ?? '', $markers);
                foreach ($markers[1] as $i => $raw) {
                    $m = strtolower($raw);
                    if ($m === 'renamed_from') {
                        if ($renamedFrom !== null) {
                            throw new SchemaException($multi);
                        }
                        $renamedFrom = trim($markers[2][$i]); // '' = previously without a label
                        continue;
                    }
                    if ($m === 'filter_by') {
                        if ($filterBy !== null) {
                            throw new SchemaException($multi);
                        }
                        $filterBy = trim($markers[2][$i]);
                        continue;
                    }
                    if (!in_array($m, ['cascade', 'label', 'unique', 'symmetric'], true)) {
                        throw new SchemaException(
                            "Unbekannte Markierung '{{$raw}}' an Relation (erlaubt: {cascade}, {label}, {unique}, "
                            . "{symmetric}, {renamed_from:Altes Label}, {filter_by:feld}): $line"
                        );
                    }
                    // {cascade} (n:1 only) and {label} (n:n only) are mutually exclusive; {unique} and {symmetric} are
                    // independent of that (each at most once)
                    if (isset($flags[$m]) ? $flags[$m] : $marker !== null) {
                        throw new SchemaException($multi);
                    }
                    if (isset($flags[$m])) {
                        $flags[$m] = true;
                    } else {
                        $marker = $m;
                    }
                }
                $label = trim($r[6] ?? '');
                // A marker before the end of the label (e.g. "x {label} y") would otherwise end up unnoticed in the label text
                if (preg_match('/\{\w*(?::[^{}]*)?\}/', $label)) {
                    throw new SchemaException($multi);
                }
                foreach ([$r[1], $r[5]] as $side) {
                    if (isset($abstract[strtolower($side)])) {
                        throw new SchemaException(
                            "Beziehung auf abstrakte Klasse '{$abstract[strtolower($side)]}' nicht möglich - abstrakte "
                            . 'Klassen haben keine eigene Tabelle (Beziehungen werden nicht vererbt; bitte je Unterklasse '
                            . "zeichnen): $line"
                        );
                    }
                }
                $applied = self::applyRelation(
                    $entities, $junctions, $r[1], $r[2], $r[4], $r[5], $label, $marker, $flags['unique'], $arrow,
                    $flags['symmetric'], $renamedFrom, $filterBy
                );
                $first = $applied['first']; // does the class named first carry the column or the n:n list?
                $relations[] = $applied + [
                    'from_multiplicity' => trim($first ? $r[2] : $r[4]), 'to_multiplicity' => trim($first ? $r[4] : $r[2]),
                    'label' => $label, 'show_label' => $marker === 'label', 'cascade' => $marker === 'cascade',
                    'unique' => $flags['unique'], 'symmetric' => $flags['symmetric'], 'renamed_from' => $renamedFrom,
                ];
            } elseif (preg_match('/^package\s/i', $line)) {
                throw new SchemaException(
                    "package-Zeile nicht verständlich (erwartet: package \"Name\" { ... } mit den Klassen darin): $line"
                );
            } elseif (preg_match('/[-.]{2}|<-|->/', $line) && $line[0] !== '@') {
                throw new SchemaException("Relation nicht verständlich (erwartet: A \"n\" --> \"1\" B : label): $line");
            } elseif ($line[0] !== '@') {
                // everything else is ignored as before (only @startuml/@enduml without a notice)
                $warn($index, "'" . (mb_strlen($line) > 60 ? mb_substr($line, 0, 57) . '...' : $line)
                    . "' wird nicht übernommen (unbekannte Anweisung, ignoriert)");
            }
        }
        $open = self::currentPackage($blocks);
        if ($open !== null) {
            throw new SchemaException("package \"$open\" wird nicht mit \"}\" geschlossen.");
        }

        // Used workflows: their states count as an enum (display, translations); action=set_visibility needs a
        // visibility field of the class (an inherited one counts too)
        $workflowEnums = [];
        foreach ($entities as $e) {
            foreach ($e['fields'] as $f) {
                if (!isset($f['workflow'])) {
                    continue;
                }
                $enums[$f['workflow']] = $f['enum_values'];
                $workflowEnums[$f['workflow']] = true;
                $hasVisibility = (bool) array_filter($e['fields'], function ($x) {
                    return $x['type'] === 'visibility';
                });
                foreach ($workflows[$f['workflow']]['transitions'] as $ti => $t) {
                    if (!$hasVisibility && in_array('set_visibility', array_column($t['actions'], 'type'), true)) {
                        throw new SchemaException("Workflow '{$f['workflow']}': Die Transition '{$t['label']}' ({$t['from']} -> "
                            . "{$t['to']}) nutzt action=set_visibility, aber die Klasse '{$e['name']}' hat kein visibility-Feld.",
                            null, ['workflow' => $f['workflow'], 'transition' => $ti, 'key' => 'action:set_visibility']);
                    }
                }
            }
        }

        $mediaJunctions = self::extractMediaFields($entities);

        // Name collision entity <-> link table, and two link tables of the same name (possible if a link table
        // renamed after its label later on, see applyRelation, hits the name of another one)
        foreach ($mediaJunctions as $table => $where) {
            if (in_array($table, self::RESERVED, true) || isset($entities[$table])) {
                throw new SchemaException(
                    "Zwischentabelle '$table' des Medien-Felds $where kollidiert mit einer Klasse gleichen Namens."
                );
            }
            if (in_array($table, array_column($junctions, 'table'), true)) {
                throw new SchemaException(
                    "Zwischentabelle '$table' des Medien-Felds $where würde doppelt entstehen (gleichnamige n:n-Zwischentabelle). "
                    . 'Bitte das Feld oder die Beziehung umbenennen.'
                );
            }
        }
        $junctionNames = array_column($junctions, 'table');
        foreach (array_diff_assoc($junctionNames, array_unique($junctionNames)) as $dup) {
            throw new SchemaException("Zwischentabelle '$dup' würde doppelt entstehen. " . self::NN_LABEL_HINT);
        }
        foreach ($junctions as $j) {
            if (in_array($j['table'], self::RESERVED, true) || isset($entities[$j['table']])) {
                throw new SchemaException("Zwischentabelle '{$j['table']}' kollidiert mit einer Klasse gleichen Namens.");
            }
        }

        return [
            'model' => [
                'entities' => $entities, 'junctions' => array_values($junctions), 'enums' => $enums,
                'abstract' => array_values($abstract),
            ] + ($workflows !== null ? ['workflows' => $workflows] : []),
            'classes' => $classes, 'relations' => $relations, 'abstract_packages' => $abstractPackages,
            'workflow_enums' => $workflowEnums,
            'warnings' => self::sortedWarnings($warnings),
        ];
    }

    /** @param array<int,array{0:int,1:string}> $warnings */
    private static function sortedWarnings(array $warnings): array
    {
        $order = array_keys($warnings);
        usort($order, function ($a, $b) use ($warnings) {
            return [$warnings[$a][0], $a] <=> [$warnings[$b][0], $b];
        });
        return array_map(function ($i) use ($warnings) {
            return "Zeile {$warnings[$i][0]}: {$warnings[$i][1]}";
        }, $order);
    }

    /**
     * Recognizes purely decorative PlantUML statements (title, skinparam, note, header/footer/legend/caption, hide/show,
     * layout direction, scale, !theme/!pragma) and connecting lines to notes ("N1 .. Class").
     *
     * @param string[] $noteAliases is extended by the aliases of "note ... as N1"
     * @return array{0:string,1:?string}|null [name for the warning, end pattern of a multi-line block or null]
     */
    private static function decoration(string $line, array &$noteAliases): ?array
    {
        if (preg_match('/^note\b/i', $line)) {
            if (preg_match('/\bas\s+(\w+)\s*$/i', $line, $alias)) {
                $noteAliases[] = strtolower($alias[1]);
            }
            // single-line: "note left of A : Text", "note "Text" as N1"; otherwise multi-line up to "end note"
            $single = preg_match('/^note\b[^"]*:/i', $line) || preg_match('/^note\s+"/i', $line);
            return ['note', $single ? null : '/^end\s*note$/i'];
        }
        if (preg_match('/^(\w+)\s*[-.]{2,}>?\s*(\w+)$/', $line, $link)
            && array_intersect([strtolower($link[1]), strtolower($link[2])], $noteAliases)) {
            return ['note', null];
        }
        if (preg_match('/^skinparam\b/i', $line)) {
            return ['skinparam', substr($line, -1) === '{' ? '/^\}$/' : null];
        }
        if (preg_match('/^(title|header|footer|legend)\b/i', $line, $m)) {
            $key = strtolower($m[1]);
            // multi-line if there is no text after the keyword (for legend/header/footer possibly only the position)
            $multi = preg_match('/^\w+(?:\s+(?:left|right|center|top|bottom))*$/i', $line);
            return [$key, $multi ? '/^end\s*' . $key . '$/i' : null];
        }
        if (preg_match('/^(caption|hide|show|scale|left\s+to\s+right\s+direction|top\s+to\s+bottom\s+direction|!theme|!pragma)\b/i', $line, $m)) {
            return [strtolower((string) preg_replace('/\s+/', ' ', $m[1])), null];
        }
        return null;
    }

    /**
     * Moves the media fields (type 'media' - carried along like normal fields up to here so that name collisions,
     * inheritance and order are handled as for any field) from fields to media. Result: link table =>
     * "Class.field" (for error messages). Two media fields with the same link table are a schema error (e.g.
     * class "A_B" with field "c" and class "A" with field "b_c").
     *
     * @return array<string,string>
     */
    private static function extractMediaFields(array &$entities): array
    {
        $junctions = [];
        foreach ($entities as $table => &$e) {
            $e['media'] = [];
            foreach ($e['fields'] as $i => $f) {
                if ($f['type'] !== self::MEDIA_TYPE) {
                    continue;
                }
                $junction = $table . '_' . strtolower($f['name']) . '_media';
                if (isset($junctions[$junction])) {
                    throw new SchemaException(
                        "Zwischentabelle '$junction' des Medien-Felds {$e['name']}.{$f['name']} würde doppelt entstehen "
                        . "(ebenso bei {$junctions[$junction]}). Bitte eines der Felder umbenennen."
                    );
                }
                $junctions[$junction] = "{$e['name']}.{$f['name']}";
                $e['media'][] = [
                    'name' => $f['name'], 'junction' => $junction, 'own_column' => $table . '_id', 'required' => $f['required'],
                ] + (isset($f['renamed_from']) ? ['renamed_from' => $f['renamed_from']] : []);
                unset($e['fields'][$i]);
            }
            $e['fields'] = array_values($e['fields']);
        }
        unset($e);
        return $junctions;
    }

    /**
     * extends part of a class line (" extends Basis" or empty): exactly one name. Several base classes ("extends A, B",
     * "extends A extends B") and anything else after it (e.g. "implements I", "<<Stereotype>>") are schema errors.
     *
     * @return string|null name of the base class as written, null = none
     */
    private static function parseExtends(string $class, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $base = trim((string) preg_replace('/^extends\b/i', '', $raw));
        if ($base === '') {
            throw new SchemaException("Klasse '$class': extends ohne Basisklasse.");
        }
        if (strpos($base, ',') !== false || preg_match('/\sextends\s/i', " $base ")) {
            throw new SchemaException(
                "Klasse '$class': Mehrfachvererbung wird nicht unterstützt ('$raw'). Nur eine Basisklasse je Klasse."
            );
        }
        if (!preg_match('/^[^\s{]+$/', $base)) {
            throw new SchemaException(
                "Klasse '$class': extends-Angabe nicht verständlich ('$raw', erwartet: class $class extends <Basisklasse> {)."
            );
        }
        return $base;
    }

    /**
     * Copies the fields of the (recursively resolved) base class in front of the own fields of every class with extends
     * and returns the concrete entities (without abstract classes) in diagram order. The id stays a single column
     * at the beginning (an explicitly written id is always "id: int" anyway, see parseClass). {title} and
     * {unique} fields: first the inherited ones, then the own ones. An own field name equal to an inherited one is an error
     * like a duplicate field, as are two visibility fields after merging.
     *
     * @param array<string,array> $classes see parse()
     * @return array<string,array>
     */
    private static function resolveInheritance(array $classes): array
    {
        $resolved = [];
        $resolve = function (string $table, array $chain) use (&$resolve, &$resolved, $classes): array {
            if (isset($resolved[$table])) {
                return $resolved[$table];
            }
            $c = $classes[$table];
            $c['origin'] = []; // field name in lower case => class it comes from (for the error message)
            foreach ($c['fields'] as $f) {
                $c['origin'][strtolower($f['name'])] = $c['name'];
            }
            if ($c['extends'] !== null) {
                $bt = strtolower($c['extends']);
                if (!isset($classes[$bt])) {
                    throw new SchemaException("Klasse '{$c['name']}' erbt von unbekannter Klasse '{$c['extends']}'.");
                }
                if ($bt === $table || in_array($bt, $chain, true)) {
                    $names = array_map(function ($t) use ($classes) {
                        return $classes[$t]['name'];
                    }, array_merge(array_slice($chain, (int) array_search($bt, $chain, true)), [$table, $bt]));
                    throw new SchemaException('Zyklische Vererbung: ' . implode(' extends ', $names) . '.');
                }
                if (!$classes[$bt]['abstract']) {
                    throw new SchemaException(
                        "Klasse '{$c['name']}' erbt von '{$classes[$bt]['name']}', die keine abstrakte Klasse ist. "
                        . "Vererbung kopiert nur Felder aus einer Vorlage ohne eigene Tabelle: bitte 'abstract class "
                        . "{$classes[$bt]['name']}' schreiben (die dann keine eigene Tabelle mehr bekommt)."
                    );
                }
                $base = $resolve($bt, array_merge($chain, [$table]));
                $own = array_values(array_filter($c['fields'], function ($f) {
                    return !$f['primary'];
                }));
                foreach ($own as $f) {
                    $l = strtolower($f['name']);
                    if (isset($base['origin'][$l]) && $l !== 'id') {
                        throw new SchemaException(
                            "Feld '{$f['name']}' in Klasse '{$c['name']}' doppelt definiert "
                            . "(bereits geerbt von '{$base['origin'][$l]}')."
                        );
                    }
                }
                $c['fields'] = array_merge($base['fields'], $own); // the id of the base is already at the front there
                $c['title_fields'] = array_merge($base['title_fields'], $c['title_fields']);
                $c['unique_fields'] = array_merge($base['unique_fields'], $c['unique_fields']);
                $c['origin'] += $base['origin'];
                $visibility = array_filter($c['fields'], function ($f) {
                    return $f['type'] === 'visibility';
                });
                if (count($visibility) > 1) {
                    throw new SchemaException(
                        "Entität {$c['name']} hat mehrere visibility-Felder (auch geerbte zählen), erlaubt ist maximal eines"
                    );
                }
            }
            return $resolved[$table] = $c;
        };
        $entities = [];
        foreach (array_keys($classes) as $table) {
            $c = $resolve($table, []);
            if (!$c['abstract']) {
                unset($c['origin'], $c['abstract']);
                $entities[$table] = $c;
            }
        }
        return $entities;
    }

    /**
     * Reads all `enum Name { ... }` blocks into $enums (name as declared => values in block order) and returns
     * the source text without them (the lines stay as empty lines) so that class and relation detection do not
     * see them. One value per line; empty lines, comments (') and separator lines (--, .., ==, __) are skipped. The
     * opening "{" is on the enum line (optionally after <<Stereotype>>/#colour), the closing "}" alone or
     * directly after the last value; `enum X {}` on one line is an empty block.
     *
     * The enum line is deliberately recognized broadly (name = everything up to whitespace, "{" or ":") so that `enum 9Status`
     * or `enum Größe{` are reported too instead of being silently skipped as "some line". ":" is not part of the name
     * so that a field `enum : string` (without a visibility sign) in a class body does not count as a block.
     *
     * @param array<string,string[]> $enums is filled
     * @param callable|null $warn fn(int line index, string text) for warnings (colour on the enum line)
     */
    private static function extractEnums(string $source, array &$enums, ?callable $warn = null): string
    {
        $lines = explode("\n", $source);
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (!preg_match('/^\s*enum\s+([^\s{:]+)(.*)$/i', $lines[$i], $m)) {
                continue;
            }
            $name = $m[1];
            $start = $i;
            if (!preg_match(self::IDENT, $name)) {
                throw new SchemaException(
                    "Ungültiger enum-Name '$name' (erlaubt: Buchstaben, Ziffern, _ ohne Umlaute, nicht mit Ziffer beginnend)."
                );
            }
            if (!preg_match('/^(?:\s*<<[^>]*>>)?(?:\s*#\w+)?\s*(?:\{(.*))?$/', $m[2], $open)) {
                throw new SchemaException("enum-Zeile nicht verständlich (erwartet: enum $name { ... }): " . trim($lines[$i]));
            }
            if (!isset($open[1])) {
                throw new SchemaException("enum '$name' hat keine Werte (erwartet: enum $name { mit einem Wert je Zeile }).");
            }
            if ($warn !== null && preg_match('/#\w+/', $m[2], $color)) {
                $warn($i, "Farbangabe '{$color[0]}' an enum '$name' wird nicht übernommen");
            }
            // lines of the block: the rest of the enum line after "{", then the following lines up to the closing "}"
            $raw = [];
            $pending = $open[1];
            $closed = false;
            while (true) {
                $t = trim($pending);
                if (substr($t, -1) === '}') {
                    $raw[] = rtrim(substr($t, 0, -1));
                    $closed = true;
                    break;
                }
                $raw[] = $t;
                if (++$i >= $n) {
                    break;
                }
                $pending = $lines[$i];
            }
            if (!$closed) {
                throw new SchemaException("enum '$name' wird nicht mit \"}\" geschlossen.");
            }
            $values = [];
            $seen = [];
            foreach ($raw as $value) {
                $value = trim($value);
                if ($value === '' || $value[0] === "'" || preg_match('/^(--|\.\.|==|__)/', $value)) {
                    continue;
                }
                if (!preg_match(self::IDENT, $value)) {
                    throw new SchemaException(
                        "Ungültiger Wert '$value' in enum '$name' (erwartet: ein Wert je Zeile aus Buchstaben, Ziffern, _ "
                        . 'ohne Umlaute, nicht mit Ziffer beginnend, z. B. OFFEN).'
                    );
                }
                if (isset($seen[strtolower($value)])) {
                    throw new SchemaException("Wert '$value' in enum '$name' doppelt angegeben.");
                }
                $seen[strtolower($value)] = true;
                $values[] = $value;
            }
            if (!$values) {
                throw new SchemaException("enum '$name' hat keine Werte (erwartet: enum $name { mit einem Wert je Zeile }).");
            }
            if (isset(self::TYPE_MAP[strtolower($name)]) || strtolower($name) === self::MEDIA_TYPE) {
                throw new SchemaException(
                    "enum '$name' heißt wie der Basistyp '" . strtolower($name) . "'. Bitte anders benennen."
                );
            }
            foreach (array_keys($enums) as $other) {
                if (strtolower($other) === strtolower($name)) {
                    throw new SchemaException("enum '$name' ist doppelt definiert.");
                }
            }
            $enums[$name] = $values;
            for ($k = $start; $k <= $i; $k++) {
                $lines[$k] = '';
            }
        }
        return implode("\n", $lines);
    }

    /**
     * `package "Name" {` or `package Name {` (optionally with `as Alias`, `<<Stereotype>>`, `#colour`) opens a
     * sidebar group. The package is a display grouping only: class names stay unique project-wide, and
     * several blocks with the same name result in one group. Schema errors: empty name, nested packages (the
     * sidebar knows only one level). The opening "{" must be on the same line.
     *
     * @return bool true = the line was a package line (and is recorded in $blocks)
     */
    private static function parsePackageLine(string $line, array &$blocks): bool
    {
        $pattern = '/^package\s+(?:"([^"]*)"|([^\s{"]+))(?:\s+as\s+[^\s{]+)?(?:\s*<<[^>]*>>)?(?:\s*#\w+)?\s*\{(\s*\})?$/i';
        if (!preg_match($pattern, $line, $m)) {
            return false;
        }
        $name = trim(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
        if ($name === '') {
            throw new SchemaException("package ohne Namen: $line");
        }
        $outer = self::currentPackage($blocks);
        if ($outer !== null) {
            throw new SchemaException(
                "Verschachtelte Packages werden nicht unterstützt (\"$name\" in \"$outer\"). "
                . 'Die Sidebar kennt nur eine Gruppierungsebene.'
            );
        }
        if (empty($m[3])) { // "package X {}" on one line: empty, closed again right away
            $blocks[] = $name;
        }
        return true;
    }

    /** Innermost open package (null = no class is currently inside a package). */
    private static function currentPackage(array $blocks): ?string
    {
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if ($blocks[$i] !== null) {
                return $blocks[$i];
            }
        }
        return null;
    }

    /**
     * @param array<string,string[]> $enums declared enums (see extractEnums())
     * @param callable|null $warn fn(int line in the body, string text) for warnings (skipped methods)
     */
    private static function parseClass(string $name, string $body, array $enums = [], ?callable $warn = null, ?array $workflows = null): array
    {
        if (!preg_match(self::IDENT, $name)) {
            throw new SchemaException("Ungültiger Klassenname '$name' (erlaubt: Buchstaben, Ziffern, _ ohne Umlaute).");
        }
        $table = strtolower($name);
        if (in_array($table, self::RESERVED, true)) {
            throw new SchemaException("Klassenname '$name' ist reserviert (" . implode(', ', self::RESERVED) . ').');
        }
        if ($table[0] === '_') {
            throw new SchemaException("Klassenname '$name' darf nicht mit _ beginnen.");
        }

        $fields = [];
        $seen = [];
        $titleFields = []; // order = diagram order, see {title} marker below
        $uniqueFields = []; // {unique} fields; FK columns with {unique} are added by applyRelation()
        foreach (explode("\n", $body) as $bodyLine => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === "'" || preg_match('/^(--|\.\.|==|__)/', $line)) {
                continue;
            }
            if (strpos($line, '(') !== false) {
                if ($warn !== null) {
                    $warn($bodyLine, "Methode '$line' in Klasse '$name' wird nicht übernommen");
                }
                continue; // method
            }
            $pattern = '/^(?:\{\w+\}\s*)?[+\-#~]?\s*([^\s:]+)\s*:\s*(\w+)\s*(\?)?\s*'
                . '((?:\{(?:title|unique|renamed_from:[A-Za-z_][A-Za-z0-9_]*)\}\s*)*)$/i';
            if (!preg_match($pattern, $line, $f)) {
                throw new SchemaException(
                    "Zeile in Klasse '$name' nicht verständlich (erwartet: + feld: typ oder + feld: typ? "
                    . "oder + feld: typ {title} und/oder {unique}, optional {renamed_from:alter_name}): $line"
                );
            }
            [, $field, $type] = $f;
            $optional = ($f[3] ?? '') === '?'; // '?' directly after the type = optional, otherwise a required field
            // {title}/{unique}/{renamed_from:x} at the end of the line, in any order, each at most once
            preg_match_all('/\{(\w+)(?::(\w+))?\}/', $f[4] ?? '', $markers);
            $markerNames = array_map('strtolower', $markers[1]);
            if (count($markerNames) !== count(array_unique($markerNames))) {
                throw new SchemaException("Feld '$field' in Klasse '$name': Markierung doppelt angegeben: $line");
            }
            $isTitle = in_array('title', $markerNames, true);
            $isUnique = in_array('unique', $markerNames, true);
            // {renamed_from:alter_name}: only for schema editing (SchemaMigration) - the data of column alter_name
            // moves into this field; without meaning at the first bootstrap
            $renamedFrom = null;
            foreach ($markerNames as $i => $m) {
                if ($m === 'renamed_from') {
                    $renamedFrom = $markers[2][$i];
                }
            }
            if ($renamedFrom !== null && strtolower($field) === 'id') {
                throw new SchemaException("Feld 'id' in Klasse '$name' kann nicht mit {renamed_from:…} markiert werden.");
            }
            $type = strtolower($type);
            if (!preg_match(self::IDENT, $field)) {
                throw new SchemaException("Ungültiger Feldname '$field' in Klasse '$name'.");
            }
            // enum name as type (case-insensitive like the base types)
            $enum = null;
            foreach (array_keys($enums) as $e) {
                if (strtolower($e) === $type) {
                    $enum = $e;
                }
            }
            // workflow field: type name ends in "Workflow" (see class comment)
            $workflow = null;
            $suffix = strtolower(WorkflowParser::SUFFIX);
            if ($enum === null && strlen($type) > strlen($suffix) && substr($type, -strlen($suffix)) === $suffix
                && strtolower($field) !== 'id') {
                $workflow = self::workflowFor($name, $field, $f[2], $workflows);
                if ($optional || $isTitle || $isUnique) {
                    throw new SchemaException("Feld '$field' in Klasse '$name': Ein Workflow-Feld ist immer Pflicht und kennt "
                        . "weder '?' noch {title} oder {unique}: $line");
                }
                foreach ($fields as $other) {
                    if (isset($other['workflow'])) {
                        throw new SchemaException("Klasse '$name' hat mehrere Workflow-Felder ('{$other['name']}' und '$field'), "
                            . 'erlaubt ist genau eines.');
                    }
                }
            }
            if ($workflow !== null) {
                $type = 'enum';
            } elseif ($enum !== null) {
                $type = 'enum';
            } elseif (!isset(self::TYPE_MAP[$type]) && $type !== self::MEDIA_TYPE) {
                throw new SchemaException(
                    "Unbekannter Typ '$type' bei $name.$field (erlaubt: "
                    . implode(', ', array_merge(array_keys(self::TYPE_MAP), [self::MEDIA_TYPE], array_keys($enums))) . ')'
                );
            }
            if ($isTitle && strtolower($field) === 'id') {
                throw new SchemaException(
                    "Feld 'id' in Klasse '$name' kann nicht mit {title} markiert werden "
                    . '(die ID wird in Anzeige-Bezeichnungen ohnehin immer als "#<id>" vorangestellt).'
                );
            }
            if ($isTitle && !in_array($type, self::TITLE_ALLOWED_TYPES, true)) {
                throw new SchemaException(
                    "Feld '$field' in Klasse '$name': {title} ist nur bei den Typen "
                    . implode(', ', array_diff(self::TITLE_ALLOWED_TYPES, ['enum'])) . " und Enum-Typen sinnvoll "
                    . "(nicht bei '$type')."
                );
            }
            if ($isUnique && $type === self::MEDIA_TYPE && strtolower($field) !== 'id') {
                throw new SchemaException(
                    "Feld '$field' in Klasse '$name': {unique} ist bei Medien-Feldern nicht möglich (eine Liste von Medien, "
                    . 'keine Spalte).'
                );
            }
            if ($isUnique && strtolower($field) === 'id') {
                throw new SchemaException(
                    "Feld 'id' in Klasse '$name' kann nicht mit {unique} markiert werden (die ID ist ohnehin eindeutig)."
                );
            }
            if (isset($seen[strtolower($field)])) {
                throw new SchemaException("Feld '$field' in Klasse '$name' doppelt definiert.");
            }
            $seen[strtolower($field)] = true;
            if ($isTitle) {
                $titleFields[] = $field;
            }
            if ($isUnique) {
                $uniqueFields[] = $field;
            }

            if (strtolower($field) === 'id') {
                // The ID is always INTEGER PRIMARY KEY AUTOINCREMENT; another type or "?" would otherwise be silently
                // skipped (found by the schema fuzzer) - hence an error instead of ignoring it.
                if ($type !== 'int' || $optional) {
                    throw new SchemaException(
                        "Das Feld 'id' in Klasse '$name' ist immer 'int' und immer Pflicht (kein '?'). "
                        . "Bitte die Zeile entfernen oder als 'id: int' schreiben."
                    );
                }
                $fields[] = [
                    'name' => 'id', 'type' => 'int', 'sql_type' => 'INTEGER',
                    'primary' => true, 'required' => false, 'foreign_key' => null,
                ];
                continue;
            }
            if ($workflow !== null) {
                $fields[] = [
                    'name' => $field, 'type' => 'enum', 'sql_type' => self::TYPE_MAP['string'],
                    'primary' => false, 'required' => true, 'foreign_key' => null,
                    'enum' => $workflow['name'], 'enum_values' => array_column($workflow['states'], 'name'),
                    'workflow' => $workflow['name'],
                ] + ($renamedFrom !== null ? ['renamed_from' => $renamedFrom] : []);
                continue;
            }
            if ($enum !== null) {
                $fields[] = [
                    'name' => $field, 'type' => 'enum', 'sql_type' => self::TYPE_MAP['string'],
                    'primary' => false, 'required' => !$optional, 'foreign_key' => null,
                    'enum' => $enum, 'enum_values' => $enums[$enum],
                ] + ($renamedFrom !== null ? ['renamed_from' => $renamedFrom] : []);
                continue;
            }
            $fields[] = [
                'name' => $field, 'type' => $type, 'sql_type' => self::TYPE_MAP[$type] ?? null, // media: no column
                'primary' => false, 'required' => !$optional, 'foreign_key' => null,
            ] + ($renamedFrom !== null ? ['renamed_from' => $renamedFrom] : []);
        }

        $visibility = array_column(array_filter($fields, function ($f) {
            return $f['type'] === 'visibility';
        }), 'name');
        if (count($visibility) > 1) {
            throw new SchemaException("Entität $name hat mehrere visibility-Felder, erlaubt ist maximal eines");
        }

        if (!isset($seen['id'])) {
            array_unshift($fields, [
                'name' => 'id', 'type' => 'int', 'sql_type' => 'INTEGER',
                'primary' => true, 'required' => false, 'foreign_key' => null,
            ]);
        }

        return [
            'name' => $name, 'table' => $table, 'fields' => $fields, 'many_to_many' => [], 'title_fields' => $titleFields,
            'unique_fields' => $uniqueFields,
            'package' => null, // is set in parse() if the class is inside a package block
        ];
    }

    /**
     * Workflow definition for a workflow field type: the workflow `<ClassName>Workflow` of the active workflow file.
     *
     * @param array<string,array>|null $workflows null = no workflow file active
     */
    private static function workflowFor(string $class, string $field, string $type, ?array $workflows): array
    {
        if ($workflows === null) {
            throw new SchemaException("Feld '$field' in Klasse '$class': Der Typ '$type' ist ein Workflow, aber es ist kein "
                . 'aktiver Workflow konfiguriert. Bitte zuerst unter System → Workflows eine Workflow-Datei anlegen und anwenden.');
        }
        if (strcasecmp($type, $class . WorkflowParser::SUFFIX) !== 0) {
            throw new SchemaException("Feld '$field' in Klasse '$class': Das Workflow-Feld einer Klasse hat den Typ "
                . "'{$class}" . WorkflowParser::SUFFIX . "' (nicht '$type').");
        }
        foreach ($workflows as $name => $def) {
            if (strcasecmp((string) $name, $type) === 0) {
                return $def;
            }
        }
        throw new SchemaException("Feld '$field' in Klasse '$class': Den Workflow '$type' gibt es in der aktiven Workflow-Datei "
            . 'nicht' . ($workflows ? ' (vorhanden: ' . implode(', ', array_keys($workflows)) . ')' : ' (sie enthält keinen Workflow)') . '.');
    }

    private static function cardinality(string $raw): string
    {
        $c = strtolower(trim($raw));
        if (in_array($c, ['n', 'm', '*', '0..*', '1..*', '0..n', '1..n'], true)) {
            return 'many';
        }
        if ($c === '1' || $c === '1..1') {
            return 'one';
        }
        if ($c === '0..1') {
            return 'optional';
        }
        throw new SchemaException("Unbekannte Multiplizität \"$raw\" (erlaubt: n, *, 1, 0..1).");
    }

    private static function applyRelation(
        array &$entities,
        array &$junctions,
        string $a,
        string $cardA,
        string $cardB,
        string $b,
        string $label,
        ?string $marker,
        bool $unique = false,
        string $arrow = '--',
        bool $symmetric = false,
        ?string $renamedFrom = null,
        ?string $filterBy = null
    ): array {
        $onDelete = $marker === 'cascade' ? 'cascade' : 'restrict';
        $ta = strtolower($a);
        $tb = strtolower($b);
        foreach ([$a => $ta, $b => $tb] as $orig => $t) {
            if (!isset($entities[$t])) {
                throw new SchemaException("Relation verweist auf unbekannte Klasse '$orig'.");
            }
        }
        $ca = self::cardinality($cardA);
        $cb = self::cardinality($cardB);

        if ($ca === 'many' && $cb === 'many') {
            // "1..*"/"1..n" would be read like "n" and the minimum count would be lost - not implemented, hence an error
            foreach ([$cardA, $cardB] as $card) {
                if (in_array(strtolower(trim($card)), ['1..*', '1..n'], true)) {
                    $what = $label !== '' ? "'$label'" : 'die Beziehung';
                    throw new SchemaException(
                        "Mindestanzahl bei n:n-Beziehungen wird aktuell nicht unterstützt (nur '0..*'/'n'). "
                        . "Bitte $what zwischen '$a' und '$b' ohne Mindestangabe definieren."
                    );
                }
            }
            $self = $ta === $tb;
            if ($symmetric && !$self) {
                throw new SchemaException(self::symmetricMisplaced($a, $b, 'n:n'));
            }
            if ($onDelete === 'cascade') {
                throw new SchemaException(
                    "{cascade} ist nur bei n:1-Relationen möglich, nicht bei der n:n-Relation '$a' -- '$b' "
                    . '(Verknüpfungen in der Zwischentabelle werden beim Löschen ohnehin immer mitgelöscht).'
                );
            }
            if ($marker === 'label' && $label === '') {
                throw new SchemaException(
                    "{label} an der n:n-Relation '$a' -- '$b' braucht ein Label davor "
                    . "(z. B. $a \"n\" -- \"n\" $b : interessiert an {label}). Ohne Label wird ohnehin der Tabellenname angezeigt."
                );
            }
            if ($unique) {
                throw new SchemaException(
                    "{unique} ist nur an Feldern und n:1-Relationen möglich, nicht an der n:n-Relation '$a' -- '$b'."
                );
            }
            // Self-reference: without a label the two columns of the link table (and, for a directed
            // relationship, the direction) could not be named
            if ($self && self::normalizeLabel($label) === '') {
                throw new SchemaException(
                    "n:n-Selbstreferenz '$a' braucht ein Label (z. B. $a \"n\" -- \"n\" $a : folgt, oder "
                    . "$a \"n\" -- \"n\" $a : befreundet mit {symmetric}); daraus werden die Spalten der Zwischentabelle benannt."
                );
            }
            // Multiple relationship (further n:n of the same class to the same target table): from now on all
            // labelled relationships of this pair are named after their label - including the existing one(s) that
            // carried the default name until now. A single n:n relationship keeps the default name even with a label.
            $siblings = array_keys(array_filter($entities[$ta]['many_to_many'], function ($m) use ($tb) {
                return $m['table'] === $tb;
            }));
            foreach ($siblings as $k) {
                $old = $entities[$ta]['many_to_many'][$k];
                [$junction, $list] = self::manyNames($ta, $tb, $old['label'], true);
                if ($junction === $old['junction']) {
                    continue;
                }
                self::rejectListFieldClash($entities[$ta], $list, $a, $b);
                $entities[$ta]['many_to_many'][$k]['junction'] = $junction;
                $entities[$ta]['many_to_many'][$k]['name'] = $list;
                foreach ($junctions as &$j) {
                    if ($j['table'] === $old['junction']) {
                        $j['table'] = $junction;
                    }
                }
                unset($j);
            }
            // a self-reference is always named after its label (there is no legacy data whose names would have to be kept)
            [$table, $list] = self::manyNames($ta, $tb, $label, $siblings || $self);
            foreach ($junctions as $j) {
                if ($j['table'] === $table) {
                    throw new SchemaException("Zwischentabelle '$table' würde doppelt entstehen. " . self::NN_LABEL_HINT);
                }
            }
            self::rejectListFieldClash($entities[$ta], $list, $a, $b);
            $filter = self::resolveFilter($entities, $ta, $tb, $filterBy);
            // Self-reference: the linked row like an FK column name "<label>_<entity>_id" so that the two
            // columns differ ("nutzer_id folgt folgt_nutzer_id")
            $other = $self ? self::normalizeLabel($label) . '_' . $tb . '_id' : $tb . '_id';
            $junctions[] = [
                'table' => $table, 'left' => $ta, 'right' => $tb,
                'left_column' => $ta . '_id', 'right_column' => $other, 'label' => $label, 'symmetric' => $symmetric,
            ];
            $entities[$ta]['many_to_many'][] = [
                'name' => $list, 'table' => $tb, 'junction' => $table,
                'own_column' => $ta . '_id', 'other_column' => $other, 'label' => $label,
                // show the label in the UI (only with {label}); otherwise the target table name
                'show_label' => $marker === 'label',
                // {symmetric}: one row per pair (smaller ID in own_column), read from both sides (see Cms)
                'symmetric' => $symmetric,
                // {filter_by}: only then in the model - relationships without it yield exactly the same model as before
            ] + ($filter !== null ? ['filter_by' => $filter] : []) + ($renamedFrom !== null ? ['renamed_from' => $renamedFrom] : []);
            // index: position in many_to_many; the name of the link table can still change through a later sibling
            // relationship (see above) and is therefore only read at the end
            return [
                'kind' => 'nn', 'from' => $ta, 'to' => $tb, 'first' => true,
                'index' => count($entities[$ta]['many_to_many']) - 1, 'filter_by' => self::filterText($filter),
            ];
        }

        $oneToOne = $ca !== 'many' && $cb !== 'many';
        // "1..*"/"1..n" on the n side ("every B has at least one A"): structurally like "n" (no additional
        // restriction in the database - cannot be enforced on creation, B always exists before its A rows), but recorded
        // as a soft rule in the model (foreign_key.min_required): delete protection for the last A row and a warning
        // on B rows without an A (see Cms). Until 2026-10-03 this was a schema error.
        $minRequired = !$oneToOne
            && in_array(strtolower(trim($ca === 'many' ? $cardA : $cardB)), ['1..*', '1..n'], true);
        if ($symmetric) {
            throw new SchemaException(self::symmetricMisplaced($a, $b, $oneToOne ? '1:1' : 'n:1'));
        }
        if ($marker === 'label') {
            throw new SchemaException(
                "{label} ist nur bei n:n-Relationen möglich, nicht bei der " . ($oneToOne ? '1:1' : 'n:1')
                . "-Relation '$a' -- '$b' (dort wird das Label ohnehin immer als Feldbeschriftung angezeigt)."
            );
        }

        $first = true; // does the class named first carry the column?
        if ($ca === 'many') {
            [$owner, $target, $required] = [$ta, $tb, $cb === 'one'];
        } elseif ($cb === 'many') {
            [$owner, $target, $required, $first] = [$tb, $ta, $ca === 'one', false];
        } elseif (substr($arrow, 0, 1) === '<' && substr($arrow, -1) !== '>') {
            // 1:1, written backwards ("B "1" <-- "0..1" A"): the arrow points at B, so A carries the column
            [$owner, $target, $required, $first] = [$tb, $ta, $ca === 'one', false];
        } else {
            // 1:1: the side named first carries the column. Required as for n:1 according to the multiplicity on the
            // target side ("A ... --> "1" B": every A has exactly one B); the multiplicity on A ("1" or "0..1") only says
            // how often a B may occur - at most once, which UNIQUE enforces (a minimum could not be enforced).
            [$owner, $target, $required] = [$ta, $tb, $cb === 'one'];
        }
        if ($oneToOne && $owner === $target && $required) {
            throw new SchemaException(
                "1:1-Selbstreferenz '$a' mit Pflicht-Seite \"1\" wird nicht unterstützt: Jede Zeile bräuchte eine andere, "
                . 'noch nicht zugeordnete Zeile derselben Tabelle - schon die erste Zeile ließe sich nie anlegen. '
                . "Optional ist sie möglich, z. B. $a \"0..1\" --> \"0..1\" $a : Nachfolger von."
            );
        }
        $filter = self::resolveFilter($entities, $owner, $target, $filterBy);
        $column = self::addForeignKey($entities, $owner, $target, $required, $label, $onDelete, $oneToOne, $minRequired, $filter);
        if ($renamedFrom !== null) {
            // schema editing: previous FK column of this relationship = old label + target table (see SchemaMigration)
            foreach ($entities[$owner]['fields'] as &$f) {
                if ($f['name'] === $column) {
                    $f['foreign_key']['renamed_from'] = $renamedFrom;
                }
            }
            unset($f);
        }
        if ($unique) {
            $entities[$owner]['unique_fields'][] = $column; // belongs to the {unique} group of the class with the FK column
        }
        return [
            'kind' => $oneToOne ? 'one_to_one' : 'n1', 'from' => $owner, 'to' => $target, 'first' => $first, 'column' => $column,
            'filter_by' => self::filterText($filter),
        ];
    }

    /**
     * {filter_by:field} or {filter_by:sourcefield=targetfield} on a relation: the selection of the target rows in the form
     * only shows rows whose target field has the same value as the source field of the record currently being edited (purely
     * a UI aid, see README; the API does not check this). $source is the class with the form field (FK column
     * or n:n list), $target the class whose rows are selected. Both need the field, with the same type (for
     * an enum: the same enum); not possible with id, FK columns and media fields.
     *
     * @return array{field:string,target_field:string}|null field names as declared; null without the marker
     */
    private static function resolveFilter(array $entities, string $source, string $target, ?string $spec): ?array
    {
        if ($spec === null) {
            return null;
        }
        $names = [$entities[$source]['name'], $entities[$target]['name']];
        $where = "{filter_by:$spec} an der Relation '{$names[0]}' -- '{$names[1]}'";
        if (!preg_match('/^(\w+)(?:\s*=\s*(\w+))?$/', $spec, $m)) {
            throw new SchemaException(
                "$where nicht verständlich (erwartet: {filter_by:feld} oder {filter_by:quellfeld=zielfeld})."
            );
        }
        $found = [];
        foreach ([[$source, $m[1]], [$target, $m[2] ?? $m[1]]] as $i => [$table, $name]) {
            foreach ($entities[$table]['fields'] as $f) {
                if (strtolower($f['name']) === strtolower($name) && !$f['primary'] && !$f['foreign_key'] && $f['type'] !== self::MEDIA_TYPE) {
                    $found[$i] = $f;
                }
            }
            if (!isset($found[$i])) {
                throw new SchemaException(
                    "$where: Klasse '{$names[$i]}' hat kein Feld '$name' (Quell- und Zielklasse brauchen beide das Filterfeld; "
                    . 'id, Beziehungs-Spalten und Medien-Felder sind dafür nicht möglich).'
                );
            }
        }
        $type = function (array $f): string {
            return $f['type'] === 'enum' ? 'Enum ' . $f['enum'] : $f['type'];
        };
        if ($type($found[0]) !== $type($found[1])) {
            throw new SchemaException(
                "$where: Die Filterfelder haben unterschiedliche Typen ('{$names[0]}.{$found[0]['name']}': " . $type($found[0])
                . ", '{$names[1]}.{$found[1]['name']}': " . $type($found[1]) . ') - beide brauchen denselben Typ (bei Enum denselben Enum).'
            );
        }
        return ['field' => $found[0]['name'], 'target_field' => $found[1]['name']];
    }

    /** {filter_by} value as written in the diagram ("sprache" or "sprache=lang"), for the model JSON */
    private static function filterText(?array $filter): ?string
    {
        if ($filter === null) {
            return null;
        }
        return $filter['field'] === $filter['target_field'] ? $filter['field'] : $filter['field'] . '=' . $filter['target_field'];
    }

    private static function symmetricMisplaced(string $a, string $b, string $kind): string
    {
        return "{symmetric} ist nur bei einer n:n-Selbstreferenz möglich (z. B. $a \"n\" -- \"n\" $a : befreundet mit "
            . "{symmetric}), nicht bei der $kind-Relation '$a' -- '$b'.";
    }

    /**
     * Names of an n:n relationship: [link table, API list]. Default `<entity>_<target>` / `<target>_ids`. Only for a
     * multiple relationship ($multiple: the same class has several n:n to the same target table) and with a label is the
     * normalized label inserted, following the same pattern as for the FK column name (see addForeignKey):
     * `<entity>_<label>_<target>` / `<label>_<target>_ids` - and likewise always for an n:n self-reference (the caller then
     * passes $multiple = true). Single n:n relationships to another class deliberately keep the default name,
     * even with a label - otherwise the tables and API fields of all existing diagrams with a labelled n:n would change
     * (e.g. "Artikel -- Tag : hat").
     *
     * @return array{0:string,1:string}
     */
    private static function manyNames(string $owner, string $target, string $label, bool $multiple): array
    {
        $prefix = $multiple ? self::normalizeLabel($label) : '';
        $base = $prefix !== '' ? $prefix . '_' . $target : $target;
        return [$owner . '_' . $base, $base . '_ids'];
    }

    /**
     * In the API the n:n list is named e.g. "<target>_ids" - a field of the class with the same name would be shadowed by it
     * (both readings block each other with 422), analogous to the FK column collision in addForeignKey.
     */
    private static function rejectListFieldClash(array $entity, string $list, string $a, string $b): void
    {
        foreach ($entity['fields'] as $f) {
            if (strtolower($f['name']) === $list) {
                throw new SchemaException(
                    "Feldname '{$f['name']}' in Klasse '$a' kollidiert mit der generierten n:n-Liste zur Beziehung "
                    . "mit '$b'. Bitte umbenennen."
                );
            }
        }
    }

    /**
     * Column name of a relationship: with a label `<label>_<targettable>_id` (label normalized), without a label as before
     * `<targettable>_id`. The concatenation with the label deliberately applies even when it creates redundancy
     * (e.g. `lieferadresse_adresse_id`) - in favour of uniqueness and predictability, no special rule for shortening.
     *
     * @param string $onDelete 'restrict' (default) or 'cascade' ({cascade} marker on the relation)
     * @param bool $oneToOne 1:1 relationship: every target row referenced at most once (implicitly UNIQUE)
     * @param bool $minRequired "1..*"/"1..n" on the n side: every target row should have at least one row (soft rule)
     * @return string the generated column name
     */
    private static function addForeignKey(
        array &$entities,
        string $owner,
        string $target,
        bool $required,
        string $label,
        string $onDelete,
        bool $oneToOne = false,
        bool $minRequired = false,
        ?array $filter = null
    ): string
    {
        $suffix = $target . '_id';
        $prefix = self::normalizeLabel($label);
        $column = $prefix !== '' ? $prefix . '_' . $suffix : $suffix;

        foreach ($entities[$owner]['fields'] as $f) {
            if (strtolower($f['name']) === $column) {
                if ($f['foreign_key'] !== null) {
                    throw new SchemaException(
                        "Spaltenname '$column' in '$owner' mehrfach erzeugt. Bitte jede Beziehung zur selben "
                        . 'Zieltabelle mit einem eigenen, eindeutigen Label versehen.'
                    );
                }
                throw new SchemaException("Spalte '$column' in '$owner' existiert bereits (Konflikt mit einem vorhandenen Feld).");
            }
        }
        $entities[$owner]['fields'][] = [
            'name' => $column, 'type' => 'int', 'sql_type' => 'INTEGER',
            'primary' => false, 'required' => $required,
            'foreign_key' => [
                'table' => $target, 'column' => 'id', 'label' => $label, 'on_delete' => $onDelete,
                'one_to_one' => $oneToOne, // 1:1: column additionally unique (see SqlGenerator::uniqueGroups)
                // Minimum count ("1..*" on the n side): the key is only then in the model - diagrams without
                // a minimum yield exactly the same model as before
            ] + ($minRequired ? ['min_required' => true] : []) + ($filter !== null ? ['filter_by' => $filter] : []),
        ];
        return $column;
    }

    /**
     * Label -> column name component: lower case, German umlauts/ß normalized to base letters
     * (ö -> o, not "oe" - e.g. "gehört zu" -> "gehort_zu"), everything other than [a-z0-9] becomes "_", underscores
     * at the edges removed. An empty label or one consisting only of special characters is treated like "no label".
     */
    public static function normalizeLabel(string $label): string
    {
        $s = mb_strtolower(trim($label), 'UTF-8');
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
        return trim($s, '_');
    }
}
