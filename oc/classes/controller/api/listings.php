<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Api_Listings extends Api_Auth {


    /**
     * Handle GET requests.
     */
    public function action_index()
    {
        try
        {
            if (is_numeric($this->request->param('id')))
            {
                $this->action_get();
            }
            else
            {
                $output = array();

                $ads = new Model_Ad();

                //search with lat and long!! nice!
                if (isset($this->_params['latitude']) AND isset($this->_params['longitude']))
                {
                    $ads->select(array(DB::expr('degrees(acos(sin(radians('.$this->_params['latitude'].')) * sin(radians(`latitude`)) + cos(radians('.$this->_params['latitude'].')) * cos(radians(`latitude`)) * cos(radians(abs('.$this->_params['longitude'].' - `longitude`))))) * 69.172'), 'distance'))
                    ->where('latitude','IS NOT',NULL)
                    ->where('longitude','IS NOT',NULL);

                    //we unset the search by lat and long if not will be duplicated
                    unset($this->_filter_params['latitude']);
                    unset($this->_filter_params['longitude']);
                }

                //only published ads
                $ads->where('status', '=', Model_Ad::STATUS_PUBLISHED);

                //if ad have passed expiration time dont show 
                if(core::config('advertisement.expire_date') > 0)
                {
                    $ads->where(DB::expr('DATE_ADD( published, INTERVAL '.core::config('advertisement.expire_date').' DAY)'), '>', Date::unix2mysql());
                }

                //make a search with q? param
                if (isset($this->_params['q']) AND strlen($this->_params['q']))
                {
                    $ads->where_open()
                        ->where('title', 'like', '%'.$this->_params['q'].'%')
                        ->or_where('description', 'like', '%'.$this->_params['q'].'%')
                        ->where_close();
                }

                //filter results by param, verify field exists and has a value
                $ads->api_filter($this->_filter_params);

                //how many? used in header X-Total-Count
                $count = $ads->count_all();

                //after counting sort values
                $ads->api_sort($this->_sort);

                //we add the order by in case was specified, this is not a column so we need to do it manually
                if (isset($this->_sort['distance']) AND isset($this->_params['latitude']) AND isset($this->_params['longitude']))
                    $ads->order_by('distance',$this->_sort['distance']);

                //pagination with headers
                $pagination = $ads->api_pagination($count,$this->_params['items_per_page']);

                $ads = $ads->cached()->find_all();

                //as array
                foreach ($ads as $ad)
                {
                    $output[$ad->id_ad] = $ad->as_array();
                    $output[$ad->id_ad]['thumb'] = ($ad->get_first_image()!==NULL)?Core::S3_domain().$ad->get_first_image():FALSE;
                    $output[$ad->id_ad]['customfields'] = Model_Field::get_by_category($ad->id_category);

                    //sorting by distance, lets add it!
                    if (isset($ad->distance))
                        $output[$ad->id_ad]['distance'] = i18n::format_measurement($ad->distance);
                }

                $this->rest_output($output,200,$count,($pagination!==FALSE)?$pagination:NULL);
            }
        }
        catch (Kohana_HTTP_Exception $khe)
        {
            $this->_error($khe);
            return;
        }
    }

    //get single ad
    public function action_get()
    {
        try
        {
            if (is_numeric($id_ad = $this->request->param('id')))
            {
                $ad = new Model_Ad();

                //get distance to the ad
                if (isset($this->_params['latitude']) AND isset($this->_params['longitude']))
                    $ad->select(array(DB::expr('degrees(acos(sin(radians('.$this->_params['latitude'].')) * sin(radians(`latitude`)) + cos(radians('.$this->_params['latitude'].')) * cos(radians(`latitude`)) * cos(radians(abs('.$this->_params['longitude'].' - `longitude`))))) * 69.172'), 'distance'));
                
                $ad->where('id_ad','=',$id_ad)
                    ->where('status','=',Model_Ad::STATUS_PUBLISHED)
                    ->cached()->find();

                if ($ad->loaded())
                {
                    $a = $ad->as_array();
                    $a['images'] = $ad->get_images();
                    $a['category'] = $ad->category->as_array();
                    $a['location'] = $ad->location->as_array();
                    $a['customfields'] = Model_Field::get_by_category($ad->id_category);
                    //sorting by distance, lets add it!
                    if (isset($ad->distance))
                        $a['distance'] = i18n::format_measurement($ad->distance);

                    $this->rest_output($a);
                }
                else
                    $this->_error(__('Advertisement not found'),404);
            }
            else
                $this->_error(__('Advertisement not found'),404);
           
        }
        catch (Kohana_HTTP_Exception $khe)
        {
            $this->_error($khe);
            return;
        }
       
    }


} // END
