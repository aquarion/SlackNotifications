<?PHP

use Sebbmyr\Teams\AbstractCard as Card;

class WikiUpdateCard extends Card {

    public $theme_colour = '';
    public $title = '';
    public $action;
    public $wiki_name;
    public $image;
    public $text;

    public $facts = array();
    public $actions = array();

    function addFact($name, $text, $link){
        $this->facts[] = ['name' => $name, 'value' => "[$text]($link)" ];
    }

    function addLink($text, $link){
        $this->actions[] = [
            "@context" => "http://schema.org",
            "@type" => "ViewAction",
            "name" => $text,
            "target" => [
                $link
            ]
        ];
    }

    function getMessage(){


        return [
            "@type" => "MessageCard",
            "@context" => "http://schema.org/extensions",
            "summary" => "Wiki Update",
            "themeColor" => $this->theme_colour,
            'title' => $this->title,
            'sections' => [
                [
                    'activityTitle' => $this->action,
                    "activitySubtitle" => "On ".$this->wiki_name,
                    "activityImage" => $this->image,
                    "facts" => $this->facts,
                    "text" => $this->text
                ]
    
            ],
            'potentialAction' => $this->actions
    
        ];


    }


}