<?php

namespace Drupal\helfi_kymp_migrations;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Migration service for project taxonomies.
 */
class TaxonomyMigrationService {

  /**
   * Taxonomy csv file.
   *
   * @var resource
   */
  private $file;

  /**
   * Absolute path to taxonomies.csv.
   *
   * @var string
   */
  protected string $projectFilePath;

  /**
   * Construct.
   */
  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->projectFilePath = $this->fileSystem->realpath(
      $this->moduleHandler->getModule('helfi_kymp_migrations')->getPath()
    ) . '/src/csv/taxonomies.csv';
  }

  /**
   * Run migrations.
   */
  public function migrate(): array {
    if (!file_exists($this->projectFilePath)) {
      return ['Project file is missing!'];
    }

    $this->file = fopen($this->projectFilePath, 'r');

    $errors = $this->migrateTaxonomies();

    fclose($this->file);

    if ($errors) {
      throw new \Exception(implode(',', $errors));
    }
    return $errors;
  }

  /**
   * Loop though csv file.
   */
  protected function rows(): iterable {
    while (!feof($this->file)) {
      $row = fgetcsv($this->file, 4096, ';');
      yield $row;
    }
  }

  /**
   * Migrate project taxonomies.
   */
  private function migrateTaxonomies(): array {
    $errors = [];
    $headers = [];
    $i = 0;
    $set = [];

    foreach ($this->rows() as $key => $row) {
      if (empty($headers)) {
        $headers = $row;
        continue;
      }

      if (empty($row)) {
        continue;
      }

      if (count($headers) != count($row)) {
        $errors[] = $key;
        continue;
      }

      if ($i < 2) {
        $set[$row[3]] = $row;
        $i++;
        continue;
      }

      if ($i == 2) {
        $taxonomy_data = [
          'vid' => $row[0],
          'name' => trim($row[1]),
          'langcode' => 'en',
        ];

        if ($row[0] == 'project_sub_district') {
          $taxonomy_data['field_parent_district'] = NULL;
        }

        $existing_term = $this->entityTypeManager->getStorage('taxonomy_term')
          ->loadByProperties(['name' => trim($row[1]), 'vid' => $row[0]]);

        if (!$existing_term) {
          $term = Term::create($taxonomy_data);
          $term->save();
          $translation = $term->hasTranslation('fi') ? $term->getTranslation('fi') : $term->addTranslation('fi');
          $translation->set('name', $set['fi'][1])
            ->save();

          $translation = $term->hasTranslation('sv') ? $term->getTranslation('sv') : $term->addTranslation('sv');
          $translation->set('name', $set['sv'][1])
            ->save();

          if (
            $term->bundle() == 'project_sub_district' &&
            $term->hasField('field_parent_district')
          ) {
            $parent = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $row[4]]);
            if ($parent) {
              $term->field_parent_district->entity = reset($parent);
              $term->save();
            }
          }
        }

        $set = [];
        $i = 0;
      }
    }
    return $errors;
  }

}
