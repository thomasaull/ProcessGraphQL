<?php

namespace ProcessWire\GraphQL\Field\Mutation;

use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Exception\ValidationException;
use Youshido\GraphQL\Config\Field\FieldConfig;
use Youshido\GraphQL\Field\InputField;

use ProcessWire\Template;
use ProcessWire\Page;
use ProcessWire\NullPage;
use ProcessWire\Field;
use ProcessWire\FieldtypePage;

use ProcessWire\GraphQL\Type\Object\TemplatedPageType;
use ProcessWire\GraphQL\Type\Input\TemplatedPageInputType;

class CreateTemplatedPage extends AbstractField {

  protected $template;

  public function __construct(Template $template)
  {
    $this->template = $template;
    parent::__construct([]);
  }

  public function getName()
  {
    $typeName = ucfirst(TemplatedPageType::normalizeName($this->template->name));
    return "create{$typeName}";
  }

  public function getType()
  {
    return new TemplatedPageType($this->template);
  }

  public function getDescription()
  {
    return "Allows you to create Pages with template `{$this->template->name}`.";
  }

  public function build(FieldConfig $config)
  {
    $config->addArgument(new InputField([
      'name' => 'page',
      'type' => new TemplatedPageInputType($this->template),
    ]));
  }

  public function resolve($value, array $args, ResolveInfo $info)
  {
    // prepare neccessary variables
    $pages = \ProcessWire\wire('pages');
    $sanitizer = \ProcessWire\wire('sanitizer');
    $fields = \ProcessWire\wire('fields');
    $values = (array) $args['page'];

    /*********************************************\
     *                                           *
     * Don't ever take sides against the family! *
     *                                           *
    \*********************************************/
    // can new pages be created for this template?
    if ($this->template->noParents === 1) throw new ValidationException("No new pages can be created for the template `{$this->template->name}`.");
    // if there could be only one page is there already a page with this template
    if ($this->template->noParents === -1 && !$pages->get("template={$this->template}") instanceof NullPage) throw new ValidationException("Only one page with template `{$this->template->name}` can be created.");
    // find the parent, make sure it exists
    $parentSelector = $values['parent'];
    $parent = $pages->find($sanitizer->selectorValue($parentSelector))->first();
    // if no parent then no good. No child should born without a parent!
    if (!$parent || $parent instanceof NullPage) throw new ValidationException("Could not find the `parent` page with `$parentSelector`.");
    // make sure it is allowed as a parent
    $parentTemplates = $this->template->parentTemplates;
    if (count($parentTemplates) && !in_array($parent->template->id, $parentTemplates)) throw new ValidationException("`parent` is not allowed as a parent.");
    // make sure parent is allowed to have children
    if ($parent->template->noChildren === 1) throw new ValidationException("`parent` is not allowed to have children.");
    // make sure the page is allowed as a child for parent
    $childTemplates = $parent->template->childTemplates;
    if (count($childTemplates) && !in_array($this->template->id, $childTemplates)) throw new ValidationException("not allowed to be a child for `parent`.");

    // check if the name is valid
    $name = $sanitizer->pageName($values['name']);
    if (!$name) throw new ValidationException('value for `name` field is invalid,');
    $taken = $pages->find("parent=$parent, name=$name")->count();
    if ($taken) throw new ValidationException('`name` is already taken.');

    // create the page
    $p = new Page();  
    $p->of(false);
    $p->template = $this->template;  
    $p->parent = $parent;
    $p->name = $name;

    // set the values from client
    unset($values['parent']);
    unset($values['name']);
    foreach ($values as $fieldName => $value) {
      $field = $fields->get($fieldName);
      if (!$field instanceof Field) continue;
      switch  ($field->type->className()) {
        case 'FieldtypePage':
          $p->setFieldValue($fieldName, implode('|', $value));
          break;
        default:
          $p->setFieldValue($fieldName, $value);
          break;
      }
    }

    // save the page to db
    if ($p->save()) return $p;

    // If we did not return till now then no good!
    throw new ResolveException("Could not create page `$name` with template `{$this->template->name}`");
  }

}