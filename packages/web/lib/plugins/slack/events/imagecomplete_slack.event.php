<?php
class ImageComplete_Slack extends Event
{
    // Class variables
    public $name = 'ImageComplete_Slack';
    public $description = 'Triggers when a host finishes imaging';
    public $author = 'Tom Elliott';
    public $active = true;
    public function onEvent($event, $data)
    {
        foreach ((array)self::getClass('SlackManager')->find() as &$Token) {
            if (!$Token->isValid()) {
                continue;
            }
            $args = array(
                'channel' => $Token->get('name'),
                'text' => "{$data[HostName]} Completed imaging",
            );
            $Token->call('chat.postMessage', $args);
            unset($Token);
        }
    }
}
$EventManager->register('HOST_IMAGE_COMPLETE', new ImageComplete_Slack());
$EventManager->register('HOST_IMAGEUP_COMPLETE', new ImageComplete_Slack());
