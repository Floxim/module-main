<?php
return array(
   'actions' => array(
       '*list_infoblock' => array(
            'name' => 'Текст',
            'defaults' => array(
                '!limit' => 0,
                '!sorting' => 'manual',
                '!sorting_dir' => 'asc'
            )
        ),
       '*list_selected*' => array(
           'disabled' => true
       ),
       '*list_filtered*' => array(
           'disabled' => true
       )
   ) 
);