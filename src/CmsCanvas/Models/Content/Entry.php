<?php 

namespace CmsCanvas\Models\Content;

use Lang, stdClass, Cache, DB, Auth, Twig, Theme, App;
use CmsCanvas\Database\Eloquent\Model;
use CmsCanvas\Content\Type\FieldType;
use CmsCanvas\Models\Content\Type\Field;
use CmsCanvas\Models\Language;
use CmsCanvas\Models\Content\Revision;
use CmsCanvas\Container\Cache\Page;
use CmsCanvas\Content\Entry\Render;
use CmsCanvas\Exceptions\PermissionDenied;
use CmsCanvas\Exceptions\Exception;
use CmsCanvas\Content\Entry\Builder\Entry as EntryBuilder;
use CmsCanvas\Support\Contracts\View\Renderable;
use CmsCanvas\Support\Contracts\Page as PageInterface;
use Twig\Loader\ArrayLoader;

class Entry extends Model implements Renderable, PageInterface {

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'entries';

    /**
     * The columns that can be mass-assigned.
     *
     * @var array
     */
    protected $fillable = [
        'title', 
        'url_title', 
        'route',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'template_flag',
        'entry_status_id',
        'author_id',
        'created_at',
        'created_at_local',
    ];

    /**
     * The columns that can NOT be mass-assigned.
     *
     * @var array
     */
    protected $guarded = ['id', 'updated_at', 'updated_at_local'];

    /**
     * Manually manage the timestamps on this class
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The columns that can sorted with the query builder orderBy method.
     *
     * @var array
     */
    protected static $sortable = [
        'id', 
        'title', 
        'route', 
        'content_type_title', 
        'entry_status_name', 
        'updated_at',
    ];

    /**
     * The column to sort by if no session order by is defined.
     *
     * @var string
     */
    protected static $defaultSortColumn = 'updated_at';

    /**
     * The the sort order that the default column should be sorted by.
     *
     * @var string
     */
    protected static $defaultSortOrder = 'desc';

    /**
     * An object used to retrive cached data
     *
     * @var \CmsCanvas\Container\Cache\Page
     */
    protected $cache;

    /**
     * Defines a one to many relationship with content types
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function contentType()
    {
        return $this->belongsTo('\CmsCanvas\Models\Content\Type', 'content_type_id');
    }

    /**
     * Defines a many to one relationship with user
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function author()
    {
        return $this->hasOne('\CmsCanvas\Models\User', 'id', 'author_id');
    }

    /**
     * Returns all data for all lanaguages for the current entry
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allData()
    {
        return $this->hasMany('CmsCanvas\Models\Content\Entry\Data', 'entry_id', 'id');
    }

    /**
     * Returns all revisions for the current entry
     * in order of newest to oldest
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function revisions()
    {
        return $this->hasMany('CmsCanvas\Models\Content\Revision', 'resource_id', 'id')
            ->where('resource_type_id', Revision::ENTRY_RESOURCE_TYPE_ID)
            ->orderBy('id', 'desc');
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        self::deleting(function($entry) {
            $entry->validateForDeletion();
        });
    }

    /**
     * Save the model to the database.
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $time = $this->freshTimestamp();
        $this->setUpdatedAt($time);

        $siteTime = $time->copy();
        $siteTime->setTimezone(config('cmscanvas.config.default_timezone'));
        $this->setUpdatedAtLocal($siteTime);

        return parent::save($options);
    }

    /**
     * Queries for content type fields with entry data
     *
     * @param  bool $skipCacheFlag
     * @return \CmsCanvas\Models\Content\Type\Field|Collection
     */
    public function getContentTypeFields($skipCacheFlag = false)
    {
        if ( ! $skipCacheFlag && $this->getCache() != null) {
            return $this->getCache()->getContentTypeFields();
        }

        $entry = $this;
        $locale = Lang::getLocale();
        $fallbackLocale = Lang::getFallback();

        $query = Field::with('type')
            ->select('content_type_fields.*', 'entry_data.data', 'entry_data.metadata')
            ->leftJoin('entry_data', function ($join) use ($entry, $locale, $fallbackLocale) {
                $join->on('entry_data.content_type_field_id', '=', 'content_type_fields.id')
                    ->where('entry_data.entry_id', '=', $entry->id)
                    ->where('entry_data.language_locale', '=' , Lang::getLocale());

                if ($locale != $fallbackLocale) {
                    $join->where('content_type_fields.translate', '=', 1);
                    $join->orOn('entry_data.content_type_field_id', '=', 'content_type_fields.id')
                        ->where('entry_data.entry_id', '=', $entry->id)
                        ->where('entry_data.language_locale', '=', Lang::getFallback())
                        ->where('content_type_fields.translate', '=', 0);
                }
            })
            ->where('content_type_fields.content_type_id', $entry->content_type_id);

        return $query->get();
    }

    /**
     * Returns an array of transalated data for the current entry
     *
     * @return array
     */
    public function getRenderedData()
    {
        $contentTypeFields = $this->getContentTypeFields();

        $locale = Lang::getLocale();
        $data = [];

        foreach ($contentTypeFields as $contentTypeField) {
            $fieldType = FieldType::factory(
                $contentTypeField, 
                $this, 
                $locale, 
                $contentTypeField->data, 
                $contentTypeField->metadata
            );
            $data[$contentTypeField->short_tag] = $fieldType->render();
        }

        $data['title'] = $this->title;
        $data['url_title'] = $this->url_title;
        $data['entry_id'] = $this->id;
        $data['created_at'] = $this->created_at;
        $data['created_at_local'] = $this->created_at_local;
        $data['updated_at'] = $this->updated_at;
        $data['updated_at_local'] = $this->updated_at_local;

        return $data;
    }

    /**
     * Returns a render instance
     *
     * @param array $parameters
     * @return \CmsCanvas\Content\Entry\Builder\Entry
     */
    public function newEntryBuilder($parameters = [])
    {
        return new EntryBuilder($this->getCache()->getResource(), $parameters);
    }

    /**
     * Creates new builder item instances for a collection 
     *
     * @param  \CmsCanvas\Models\Content\Entry|collection
     * @return \CmsCanvas\Content\Entry\Builder\Entry|array
     */
    public static function newEntryBuilderCollection($entries)
    {
        $entryBuilders = [];
        $entryCount = count($entries);
        $counter = 1;

        foreach ($entries as $entry) {
            $entryBuilder = $entry->newEntryBuilder();

            $entryBuilder->setIndex($counter - 1);

             if ($counter !== 1) {
                $entryBuilder->setFirstFlag(false);
            }

            if ($counter !== $entryCount) {
                $entryBuilder->setLastFlag(false);
            }

            $entryBuilders[] = $entryBuilder;
            $counter++;
        }

        return $entryBuilders;
    }

    /**
     * Returns a render instance
     *
     * @param array $parameters
     * @return \CmsCanvas\Content\Entry\Render
     */
    public function render($parameters = [])
    {
        return $this->newEntryBuilder($parameters)->render();
    }

    /**
     * Renders an entry page from cache
     *
     * @param \CmsCanvas\Container\Cache\Page $cache
     * @return self
     */
    public function setCache(\CmsCanvas\Container\Cache\Page $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Returns an entry page from cache
     *
     * @return \CmsCanvas\Container\Cache\Page $cache
     */
    public function getCache()
    {
        if ($this->cache == null) {
            $entry = $this;

            $this->cache = Cache::rememberForever($this->getRouteName(), function() use($entry) {
                return new Page($entry->id, 'entry');
            });
        }

        return $this->cache;
    }

    /**
     * Unsets the cache object on the current entry
     *
     * @return self
     */
    public function clearCache()
    {
        $this->cache = null;

        return $this;
    }

    /**
     * Returns the route name for the entry
     *
     * @return string
     */
    public function getRouteName()
    {
        $locale = Lang::getLocale();

        return 'entry.'.$this->id.'.'.$locale;
    }

    /**
     * Returns the full route for the entry
     *
     * @return string|null
     */
    public function getRoute()
    {
        if ($this->isHomePage()) {
            return '/';
        }

        if ($this->route !== null && $this->route !== '') {
            $route = '';
            if ($this->contentType->entry_route_prefix !== null 
                && $this->contentType->entry_route_prefix !== ''
            ) {
                $route .= '/'.$this->contentType->entry_route_prefix;
            }

            return $route.'/'.$this->route;
        }

        return null;
    }

    /**
     * Returns the URI for the current entry
     *
     * @return string
     */
    public function getUri()
    {
        $uri = null;

        if ($this->getRoute() !== null) {
            $uri = $this->getRoute();
        } elseif ($this->contentType->entry_uri_template !== null
            && $this->contentType->entry_uri_template !== ''
        ) {
            $twig = App::make('twig');

            $currentLoader = $twig->getLoader();
            $currentCache = $twig->getCache();

	    $arrayContent = ['entry_uri_template' => $this->contentType->entry_uri_template];

            $twig->setCache(false);
            $twig->setLoader(new ArrayLoader($arrayContent));

            $uri = $twig->render(
		'entry_uri_template',
                $this->getRenderedData()
            );

            $twig->setCache($currentCache);
            $twig->setLoader($currentLoader);

            $uri = '/'.$uri;
        }

        return $uri;
    }

    /**
     * Returns the URL for the current entry
     *
     * @return string
     */
    public function getUrl()
    {
        if ($this->getUri() != null) {
            return url($this->getUri());
        }

        return null;
    }

    /**
     * Remove the first and last forward slash from the route
     *
     * @param string $value
     * @return string
     */
    public function getRouteAttribute($value)
    {
        return trim($value, '/');
    }

    /**
     * Remove the first and last forward slash from the route
     *
     * @param string $value
     * @return void
     */
    public function setRouteAttribute($value)
    {
        $value = trim($value, '/');

        if ($value === '') {
            $value = null;
        }

        $this->attributes['route'] = $value;
    }

    /**
     * Get the value of the "updated at local" attribute.
     *
     * @param  string  $value
     * @return \Carbon\Carbon
     */
    public function getUpdatedAtLocalAttribute($value)
    {
        return $this->asDateTime( 
            $value,
            config('cmscanvas.config.default_timezone')
        );
    }

    /**
     * Set the value of the "updated at local" attribute.
     *
     * @param  string  $value
     * @return $this
     */
    public function setUpdatedAtLocalAttribute($value)
    {
        $this->attributes['updated_at_local'] = $this->fromDateTime(
            $value,
            config('cmscanvas.config.default_timezone')
        );
    }

    /**
     * Set the value of the "updated at local" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setUpdatedAtLocal($value)
    {
        $this->updated_at_local = $value;

        return $this;
    }

    /**
     * Get the value of the "created at local" attribute.
     *
     * @param  string  $value
     * @return \Carbon\Carbon
     */
    public function getCreatedAtLocalAttribute($value)
    {
        return $this->asDateTime( 
            $value,
            config('cmscanvas.config.default_timezone')
        );
    }

    /**
     * Set the value of the "created at local" attribute.
     *
     * @param  string  $value
     * @return $this
     */
    public function setCreatedAtLocalAttribute($value)
    {
        $this->attributes['created_at_local'] = $this->fromDateTime(
            $value,
            config('cmscanvas.config.default_timezone')
        );
    }

    /**
     * Sets the entry's meta title, description, and keywords to the theme
     *
     * @return self
     */
    public function includeThemeMetadata()
    {
        $metaTitle = $this->meta_title;
        // If a meta title is not provided then auto-generate one
        if ($metaTitle == null) {
            $metaTitle =  $this->title.' | '.config('cmscanvas.config.site_name');
        }

        Theme::setMetaTitle($metaTitle);
        Theme::setMetaDescription($this->meta_description);
        Theme::setMetaKeywords($this->meta_keywords);

        return $this;
    }

    /**
     * Sets the content type's page head content to the theme
     *
     * @return self
     */
    public function includeThemePageHead()
    {
        $data = $this->getRenderedData();
        $this->contentType->includeThemePageHead($data);

        return $this;
    }

    /**
     * Checks if the current entry can be deleted.
     *
     * @throws \CmsCanvas\Exceptions\PermissionDenied
     * @throws \CmsCanvas\Exceptions\Exception
     * @return bool|string
     */
    public function validateForDeletion()
    {
        $permission = null;

        if ($this->contentType->adminEntryDeletePermission != null) {
            $permission = $this->contentType->adminEntryDeletePermission->key_name;
        }

        if ($permission != null && ! Auth::user()->can($permission)) {
            throw new PermissionDenied(
                $permission,
                "You do not have permission to delete the entry \"{$this->title}\","
                . " please refer to your system administrator."
            );
        }

        if ($this->isHomePage()) {
            throw new Exception(
                "The entry \"{$this->title}\" can not be deleted because it set as the default home page"
            );
        }

        if ($this->isCustom404Page()) {
            throw new Exception(
                "The entry \"{$this->title}\" can not be deleted because it set as the default custom 404 page."
            );
        }

        return true;
    }

    /**
     * Sets data order by using a custom object
     *
     * @param Builder $query
     * @param OrderBy $orderBy
     * @return Builder
     */
    public function scopeApplyOrderBy($query, \CmsCanvas\Container\Database\OrderBy $orderBy)
    {
        if (in_array($orderBy->getColumn(), self::$sortable)) {
            $query->orderBy($orderBy->getColumn(), $orderBy->getSort()); 
        }

        return $query;
    } 

    /**
     * Filters and queries using a custom object
     *
     * @param Builder $query
     * @param object $filter
     * @return Builder
     */
    public function scopeApplyFilter($query, $filter)
    {
        if (isset($filter->search) && $filter->search != '') {
            $query->where('entries.title', 'LIKE', "%{$filter->search}%");
        }

        if ( ! empty($filter->content_type_id)) {
            $query->where('content_type_id', $filter->content_type_id); 
        }

        if ( ! empty($filter->entry_status_id)) {
            $query->where('entry_status_id', $filter->entry_status_id); 
        }

        return $query;
    }

    /**
     * Checks if the current entry is set as the default 
     * home page in the settings
     *
     * @return bool
     */
    public function isHomePage()
    {
        return ($this->id == config('cmscanvas.config.site_homepage'));
    }

    /**
     * Checks if the current entry is set as the default 
     * custom 404 page in the settings
     *
     * @return bool
     */
    public function isCustom404Page()
    {
        return ($this->id == config('cmscanvas.config.custom_404'));
    }

    /**
     * Creates an entry revision with the data provided
     *
     * @param  array $data
     * @return void
     */
    public function createRevision(array $data)
    {
        // Create a revision only if max revisions is set
        if (empty($this->contentType->max_revisions)) {
            return;
        }

        $oldRevisions = $this->revisions()
            ->skip($this->contentType->max_revisions - 1)
            ->take(25)
            ->get();

        foreach ($oldRevisions as $oldRevision) {
            $oldRevision->delete();
        }

        $currentUser = Auth::user();

        $revision = new Revision;
        $revision->resource_type_id = Revision::ENTRY_RESOURCE_TYPE_ID;
        $revision->resource_id = $this->id;
        $revision->content_type_id = $this->contentType->id;
        $revision->author_id = $currentUser->id;
        $revision->author_name = $currentUser->getFullName(); // Saved in case the user record is ever deleted
        $revision->data = $data;
        $revision->save();
    }

    /**
     * Returns date fields as a carbon instance
     *
     * @return array
     */
    public function getDates()
    {
        return ['created_at', 'updated_at'];
    }

}
