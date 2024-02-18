<?php
namespace VNS\Admin\Plugin;

class AllowMp4MimeType
{
    public function beforeValidateFile(\Magento\MediaStorage\Model\File\Uploader $subject, $file)
    {
        if ($file['type'] === 'video/mp4') {
            return true; // Allow MP4 MIME type
        }
        // Continue with Magento's default validation for other file types
        return null;
    }
}