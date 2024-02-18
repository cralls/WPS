<?php
namespace VNS\Admin\Plugin;

class AllowMp4Upload
{
    public function beforeGetAllowedExtensions(\Magento\Cms\Model\Wysiwyg\Images\Storage $subject, $extensions)
    {
        if (is_array($extensions)) {
            $extensions[] = 'mp4'; // Adding MP4 to the allowed extensions
        }
        return [$extensions];
    }
}
